<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model;

use Laminas\Http\Request as HttpRequest;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\PurgeException;
use SR\Cloudflare\Exception\RetryablePurgeException;
use SR\Gateway\Api\Http\Client\ClientInterface;
use SR\Gateway\Api\LoggerInterface;
use SR\Gateway\Model\Http\TransferBuilderFactory;

class CloudflareClient
{
    private const MAX_OPERATIONS_PER_REQUEST = 100;
    private const PURGE_TYPE_TAGS = 'tags';
    private const PURGE_TYPE_FILES = 'files';
    private const RATE_LIMIT_ERROR_CODE = 1134;

    public function __construct(
        private readonly CacheConfig $config,
        private readonly ClientInterface                  $restClient,
        private readonly TransferBuilderFactory           $transferBuilderFactory,
        private readonly LoggerInterface                  $logger
    ) {
    }

    /**
     * Purge cached pages by cache tags.
     *
     * @param string[] $tags
     */
    public function purgeByTags(array $tags): void
    {
        $this->purgeByType(self::PURGE_TYPE_TAGS, $tags);
    }

    /**
     * Purge all cached pages by site-wide tag (hostname).
     *
     * Uses tag-based purge instead of purge_everything
     * to avoid clearing Cloudflare Image Transformations cache.
     */
    public function purgeAll(): void
    {
        $siteTags = $this->config->getAllSiteTags();
        $this->purgeByTags($siteTags);
    }

    /**
     * Purge cached pages by URLs (fallback method).
     *
     * @param string[] $urls
     */
    public function purgeByUrls(array $urls): void
    {
        $this->purgeByType(self::PURGE_TYPE_FILES, $urls);
    }

    /**
     * @param string[] $values
     */
    public function purgeByType(string $type, array $values): void
    {
        if (!in_array($type, [self::PURGE_TYPE_TAGS, self::PURGE_TYPE_FILES], true)) {
            throw new PurgeException(sprintf('Unsupported Cloudflare purge type "%s"', $type));
        }

        $values = $this->normalizeValues($type, $values);

        if (empty($values)) {
            return;
        }

        foreach (array_chunk($values, self::MAX_OPERATIONS_PER_REQUEST) as $batch) {
            $this->sendPurgeRequest([$type => $batch]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sendPurgeRequest(array $body): array
    {
        if (!$this->config->isConfigured()) {
            return [];
        }

        $url = $this->config->getResolvedApiUrl();

        try {
            $transfer = $this->transferBuilderFactory->create()
                ->setMethod(HttpRequest::METHOD_POST)
                ->setUri($url)
                ->setHeaders([
                    'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                    'Content-Type'  => 'application/json',
                ])
                ->setBody($body)
                ->shouldEncode(true)
                ->build();

            $response = $this->restClient->placeRequest($transfer);
            $payload = $response['object'] ?? [];

            if (!is_array($payload) || ($payload['success'] ?? false) !== true) {
                $message = $this->formatErrorMessage($payload);

                if ($this->isRateLimitPayload($payload)) {
                    throw new RetryablePurgeException($message);
                }

                throw new PurgeException($message);
            }

            return $payload;
        } catch (PurgeException $e) {
            $this->logger->error(
                'SR_Cloudflare: Purge API request failed: ' . $e->getMessage()
            );
            throw $e;
        } catch (\Exception $e) {
            $message = 'SR_Cloudflare: Purge API request failed: ' . $e->getMessage();
            $this->logger->error(
                $message
            );

            if ($this->isRateLimitMessage($e->getMessage())) {
                throw new RetryablePurgeException($message, null, 0, $e);
            }

            throw new PurgeException($message, 0, $e);
        }
    }

    /**
     * @param mixed[] $values
     * @return string[]
     */
    private function normalizeValues(string $type, array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $normalized[] = $type === self::PURGE_TYPE_TAGS ? strtolower($value) : $value;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param mixed $payload
     */
    private function formatErrorMessage($payload): string
    {
        if (!is_array($payload)) {
            return 'Cloudflare purge API returned an invalid response.';
        }

        $errors = $payload['errors'] ?? [];

        if (!is_array($errors) || empty($errors)) {
            return 'Cloudflare purge API returned success=false.';
        }

        $messages = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            $code = $error['code'] ?? null;
            $message = (string) ($error['message'] ?? 'Unknown Cloudflare error');
            $messages[] = $code !== null ? sprintf('%s: %s', $code, $message) : $message;
        }

        return $messages !== [] ? implode('; ', $messages) : 'Cloudflare purge API request failed.';
    }

    /**
     * @param mixed $payload
     */
    private function isRateLimitPayload($payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        $errors = $payload['errors'] ?? [];

        if (!is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (!is_array($error)) {
                continue;
            }

            if ((int) ($error['code'] ?? 0) === self::RATE_LIMIT_ERROR_CODE) {
                return true;
            }

            if ($this->isRateLimitMessage((string) ($error['message'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private function isRateLimitMessage(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'rate limit')
            || str_contains($message, 'too many requests')
            || str_contains($message, '429');
    }
}
