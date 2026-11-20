<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Gateway\Model\Http\Adapter\CurlAdapter;
use SR\Gateway\Model\Http\Adapter\CurlAdapterFactory;

class CloudflareApi
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';
    private const PAGE_SIZE = 50;
    private const USER_TOKEN_VERIFY_PATH = '/user/tokens/verify';

    public function __construct(
        private readonly CacheConfig $config,
        private readonly CurlAdapterFactory $clientAdapterFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function verifyToken(): void
    {
        $accountVerifyPath = $this->accountPath() . '/tokens/verify';
        $token = trim((string) $this->config->getApiToken());

        if (str_starts_with($token, 'cfut_')) {
            $verification = $this->request('GET', self::USER_TOKEN_VERIFY_PATH);
        } else {
            try {
                $verification = $this->request('GET', $accountVerifyPath);
            } catch (CloudflareApiException $exception) {
                // Legacy user and account API tokens have the same unprefixed format.
                if ($exception->getCode() !== 401 || !preg_match('/^[A-Za-z0-9_-]{40}$/D', $token)) {
                    throw $exception;
                }

                $verification = $this->request('GET', self::USER_TOKEN_VERIFY_PATH);
            }
        }

        if (($verification['result']['status'] ?? '') !== 'active') {
            throw new CloudflareApiException(__('The Cloudflare API token is not active.'));
        }
    }

    /** @return array<string, string> Zone ID to domain */
    public function getZones(): array
    {
        $account = $this->config->getAccountId();
        $this->accountPath();
        $zones = [];
        $page = 1;

        do {
            $query = http_build_query(['account.id' => $account, 'page' => $page, 'per_page' => self::PAGE_SIZE]);
            $response = $this->request('GET', '/zones?' . $query);
            $results = $response['result'] ?? null;
            if (!is_array($results)) {
                throw new CloudflareApiException(__('Cloudflare returned an invalid zone list.'));
            }
            foreach ($results as $zone) {
                $id = $zone['id'] ?? '';
                $name = $zone['name'] ?? '';
                if (($zone['account']['id'] ?? '') !== $account
                    || !is_string($id) || !preg_match('/^[a-f0-9]{32}$/D', $id)
                    || !is_string($name) || !filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                ) {
                    throw new CloudflareApiException(__('Cloudflare returned an invalid zone.'));
                }
                $zones[$id] = strtolower($name);
            }
            $totalPages = $response['result_info']['total_pages'] ?? null;
            $hasMore = $totalPages === null ? count($results) === self::PAGE_SIZE : $page < (int) $totalPages;
            $page++;
        } while ($hasMore);

        return $zones;
    }

    /** @return array{versions: list<string>, deployedAt: string}|null */
    public function getActiveDeployment(): ?array
    {
        try {
            $response = $this->request('GET', $this->workerPath() . '/deployments?per_page=1');
        } catch (CloudflareApiException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }
            throw $exception;
        }

        $deployments = $response['result']['deployments'] ?? null;
        if (!is_array($deployments)) {
            throw new CloudflareApiException(__('Cloudflare returned an invalid deployment list.'));
        }
        $deployment = $deployments[0] ?? null;
        if ($deployment === null) {
            return null;
        }

        $versions = array_values(array_filter(
            array_column($deployment['versions'] ?? [], 'version_id'),
            'is_string'
        ));
        $created = $deployment['created_on'] ?? '';
        if ($versions === [] || !is_string($created) || strtotime($created) === false) {
            throw new CloudflareApiException(__('Cloudflare returned an invalid deployment.'));
        }

        return ['versions' => $versions, 'deployedAt' => $created];
    }

    public function workerPath(): string
    {
        $name = $this->config->getWorkerName();
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $name)) {
            throw new CloudflareApiException(__('Enter a valid Cloudflare Worker name.'));
        }

        return $this->accountPath() . '/workers/scripts/' . $name;
    }

    public function request(string $method, string $path, ?array $body = null, bool $multipart = false): array
    {
        return $this->send($method, $path, $body, $multipart);
    }

    private function accountPath(): string
    {
        $account = $this->config->getAccountId();
        if (!preg_match('/^[a-f0-9]{32}$/D', $account)) {
            throw new CloudflareApiException(__('Enter a valid Cloudflare Account ID.'));
        }

        return '/accounts/' . $account;
    }

    private function send(string $method, string $path, ?array $body, bool $multipart): array
    {
        $token = trim((string) $this->config->getApiToken());
        if ($token === '' || preg_match('/\s/', $token)) {
            throw new CloudflareApiException(__('Enter a valid Cloudflare API token.'));
        }

        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        $requestBody = '';
        if ($body !== null) {
            if (!$multipart) {
                $headers[] = 'Content-Type: application/json';
            }
            $headers[] = 'Expect:';
            $requestBody = $multipart ? $body : $this->json->serialize($body);
        }

        $timeout = $multipart ? 45 : 15;
        $client = $this->clientAdapterFactory->create();
        $client->setOptions([
            'timeout' => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $logPath = preg_replace('#/(accounts|zones|scripts|routes)/[^/]+#', '/$1/{id}', explode('?', $path, 2)[0]);
        $log = [
            'client' => CurlAdapter::class,
            'client_config' => ['timeout' => $timeout, 'connect_timeout' => 5],
            'endpoint_url' => self::BASE_URL . $logPath,
            'headers' => [
                'Authorization' => 'Bearer [redacted]',
                'Accept' => 'application/json',
            ],
            'request_method' => $method,
            'request' => $this->getLogRequestBody($body, $multipart, $logPath, $token),
        ];
        if ($body !== null) {
            $log['headers']['Content-Type'] = $multipart ? 'multipart/form-data' : 'application/json';
        }
        $started = microtime(true);
        try {
            $client->write($method, self::BASE_URL . $path, '1.1', $headers, $requestBody);
            $httpResponse = $client->singleExec();
        } catch (\Exception) {
            $this->logger->debug(var_export($log + [
                'http_status' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'response' => 'Could not connect to the Cloudflare API.',
            ], true));
            throw new CloudflareApiException(__('Could not connect to the Cloudflare API.'));
        } finally {
            $client->close();
        }

        $status = (int) $httpResponse->getStatusCode();
        $log += [
            'http_status' => $status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        $responseBody = $httpResponse->getBody();
        try {
            $response = $method === 'DELETE' && $status >= 200 && $status < 300 && trim($responseBody) === ''
                ? ['success' => true]
                : $this->json->unserialize($responseBody);
        } catch (\InvalidArgumentException) {
            $response = null;
        }
        $codes = is_array($response['errors'] ?? null) ? array_column($response['errors'], 'code') : [];
        $codes = array_values(array_filter(array_map('intval', $codes)));
        $this->logger->debug(var_export($log + [
            'response' => $this->getLogResponseBody(is_array($response) ? $response : null, $codes),
        ], true));

        if ($status < 200 || $status >= 300 || !is_array($response) || ($response['success'] ?? false) !== true) {
            $retryHeader = $httpResponse->getHeaders()->get('Retry-After');
            $retry = $retryHeader ? $retryHeader->getFieldValue() : null;
            throw new CloudflareApiException(
                __('Cloudflare API request failed (HTTP %1; error codes: %2).', $status, implode(', ', $codes) ?: '—'),
                $status,
                $status === 429 || in_array(1134, $codes, true),
                is_numeric($retry) ? (int) $retry : null
            );
        }

        return $response;
    }

    private function getLogRequestBody(?array $body, bool $multipart, string $path, string $token): string
    {
        if ($body === null) {
            return '';
        }
        if ($multipart) {
            return '[multipart upload]';
        }
        if (str_ends_with($path, '/purge_cache')) {
            $details = array_intersect_key($body, ['tags' => true]);
            if (is_array($body['files'] ?? null)) {
                $details['files_count'] = count($body['files']);
            }

            return str_replace($token, '[redacted]', $this->json->serialize($details));
        }
        if (str_ends_with($path, '/subdomain')) {
            return $this->json->serialize(array_intersect_key($body, ['enabled' => true, 'previews_enabled' => true]));
        }

        return '[JSON body omitted]';
    }

    private function getLogResponseBody(?array $response, array $codes): string
    {
        if ($response === null) {
            return '[invalid JSON response]';
        }

        $summary = [
            'success' => ($response['success'] ?? false) === true,
            'errors' => array_map(static fn(int $code): array => ['code' => $code], $codes),
        ];
        $result = $response['result'] ?? null;
        if (is_array($result)) {
            if (array_is_list($result)) {
                $summary['result_count'] = count($result);
            } elseif (isset($result['deployments']) && is_array($result['deployments'])) {
                $summary['deployment_count'] = count($result['deployments']);
            } else {
                if (isset($result['id'])) {
                    $summary['result']['id'] = '{id}';
                }
                if (in_array($result['status'] ?? null, ['active', 'disabled', 'expired'], true)) {
                    $summary['result']['status'] = $result['status'];
                }
            }
        }

        return $this->json->serialize($summary);
    }
}
