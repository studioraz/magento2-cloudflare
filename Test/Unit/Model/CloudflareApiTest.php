<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model;

use Laminas\Http\Response;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Gateway\Model\Http\Adapter\CurlAdapter;
use SR\Gateway\Model\Http\Adapter\CurlAdapterFactory;

class CloudflareApiTest extends TestCase
{
    private const ACCOUNT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testAcceptsAnEmptySuccessfulDeleteResponse(): void
    {
        $response = new Response();
        $response->setStatusCode(204);
        $http = $this->createMock(CurlAdapter::class);
        $http->method('singleExec')->willReturn($response);
        $api = $this->createApi($http);

        self::assertSame(['success' => true], $api->request('DELETE', $api->workerPath()));
    }

    #[DataProvider('verificationPaths')]
    public function testVerifiesTokenUsingTheAppropriateEndpoint(string $token, string $path): void
    {
        $http = $this->createMock(CurlAdapter::class);
        $http->expects(self::once())->method('write')->with('GET', 'https://api.cloudflare.com/client/v4' . $path);
        $http->method('singleExec')->willReturn($this->buildResponse([
            'success' => true,
            'result' => ['status' => 'active'],
        ]));

        $this->createApi($http, token: $token)->verifyToken();
    }

    public static function verificationPaths(): array
    {
        return [
            'user token' => ['cfut_test-token', '/user/tokens/verify'],
            'account token' => ['cfat_test-token', '/accounts/' . self::ACCOUNT . '/tokens/verify'],
        ];
    }

    public function testRetriesVerificationForAnUnprefixedTokenAfterAccountUnauthorizedResponse(): void
    {
        $http = $this->createMock(CurlAdapter::class);
        $paths = [];
        $http->expects(self::exactly(2))->method('write')->willReturnCallback(
            static function (string $method, string $url) use (&$paths): string {
                self::assertSame('GET', $method);
                $paths[] = parse_url($url, PHP_URL_PATH);
                return '';
            }
        );
        $http->method('singleExec')->willReturnOnConsecutiveCalls(
            $this->buildResponse(['success' => false, 'errors' => [['code' => 1000]]], 401),
            $this->buildResponse(['success' => true, 'result' => ['status' => 'active']])
        );

        $this->createApi($http, token: str_repeat('a', 40))->verifyToken();
        self::assertSame([
            '/client/v4/accounts/' . self::ACCOUNT . '/tokens/verify',
            '/client/v4/user/tokens/verify',
        ], $paths);
    }

    public function testGetsEveryPageForTheConfiguredAccount(): void
    {
        $first = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $second = 'cccccccccccccccccccccccccccccccc';
        $responses = [
            ['result' => [['id' => $first, 'name' => 'example.com', 'account' => ['id' => self::ACCOUNT]]]],
            ['result' => [['id' => $second, 'name' => 'example.org', 'account' => ['id' => self::ACCOUNT]]]],
        ];
        $http = $this->createMock(CurlAdapter::class);
        $urls = [];
        $http->expects(self::exactly(2))->method('write')->willReturnCallback(
            static function (string $method, string $url) use (&$urls): string {
                self::assertSame('GET', $method);
                $urls[] = $url;
                return '';
            }
        );
        $http->method('singleExec')->willReturnCallback(function () use (&$responses): Response {
            return $this->buildResponse(array_shift($responses) + [
                'success' => true,
                'result_info' => ['total_pages' => 2],
            ]);
        });

        self::assertSame([$first => 'example.com', $second => 'example.org'], $this->createApi($http)->getZones());
        self::assertStringContainsString('account.id=' . self::ACCOUNT . '&page=1', $urls[0]);
        self::assertStringContainsString('account.id=' . self::ACCOUNT . '&page=2', $urls[1]);
    }

    public function testReportsActiveVersionIdsAndDeploymentTime(): void
    {
        $http = $this->createMock(CurlAdapter::class);
        $http->expects(self::once())->method('write')->with(
            'GET',
            'https://api.cloudflare.com/client/v4/accounts/' . self::ACCOUNT
            . '/workers/scripts/magento-fpc/deployments?per_page=1'
        );
        $http->method('singleExec')->willReturn($this->buildResponse([
            'success' => true,
            'result' => ['deployments' => [
                ['created_on' => '2026-11-19T08:00:00Z', 'versions' => [
                    ['version_id' => 'active-id', 'percentage' => 75],
                    ['version_id' => 'previous-id', 'percentage' => 25],
                ]],
                ['created_on' => '2026-11-18T08:00:00Z', 'versions' => [['version_id' => 'another-id']]],
            ]],
        ]));

        self::assertSame([
            'versions' => ['active-id', 'previous-id'],
            'deployedAt' => '2026-11-19T08:00:00Z',
        ], $this->createApi($http)->getActiveDeployment());
    }

    public function testDoesNotExposeProviderErrorText(): void
    {
        $http = $this->createMock(CurlAdapter::class);
        $http->method('singleExec')->willReturn($this->buildResponse([
            'success' => false,
            'errors' => [['code' => 1134, 'message' => 'private text']],
        ], 429, ['Retry-After' => '27']));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->willReturnCallback(
            static function (string $message): void {
                self::assertStringStartsWith("array (\n", $message);
                self::assertStringContainsString('/zones/{id}/purge_cache', $message);
                self::assertStringContainsString("'request_method' => 'POST'", $message);
                self::assertStringContainsString("'Authorization' => 'Bearer [redacted]'", $message);
                self::assertStringContainsString('{"tags":["cat_p_42","[redacted]"]}', $message);
                self::assertStringContainsString("'http_status' => 429", $message);
                self::assertStringContainsString('{"success":false,"errors":[{"code":1134}]}', $message);
                foreach (['private text', 'test-token', 'private-query', self::ACCOUNT] as $secret) {
                    self::assertStringNotContainsString($secret, $message);
                }
            }
        );

        try {
            $this->createApi($http, $logger)->request(
                'POST',
                '/zones/' . self::ACCOUNT . '/purge_cache?marker=private-query',
                ['tags' => ['cat_p_42', 'test-token']]
            );
            self::fail('Expected an API exception');
        } catch (CloudflareApiException $exception) {
            self::assertTrue($exception->isRateLimited());
            self::assertSame(27, $exception->getRetryAfter());
            self::assertStringNotContainsString('private text', $exception->getMessage());
        }
    }

    public function testSendsWorkerUploadAsMultipartPut(): void
    {
        $http = $this->createMock(CurlAdapter::class);
        $parts = ['metadata' => new \CURLStringFile('{}', 'metadata.json', 'application/json')];
        $http->expects(self::once())->method('write')->with(
            'PUT',
            'https://api.cloudflare.com/client/v4/accounts/' . self::ACCOUNT . '/workers/scripts/magento-fpc',
            '1.1',
            ['Authorization: Bearer test-token', 'Accept: application/json', 'Expect:'],
            $parts
        );
        $http->expects(self::once())->method('close');
        $http->method('singleExec')->willReturn($this->buildResponse(['success' => true]));

        $this->createApi($http)->request(
            'PUT',
            '/accounts/' . self::ACCOUNT . '/workers/scripts/magento-fpc',
            $parts,
            true
        );
    }

    private function buildResponse(array $payload, int $status = 200, array $headers = []): Response
    {
        $response = new Response();
        $response->setStatusCode($status);
        $response->getHeaders()->addHeaders($headers);
        $response->setContent(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response;
    }

    private function createApi(
        CurlAdapter $client,
        ?LoggerInterface $logger = null,
        string $token = 'test-token'
    ): CloudflareApi
    {
        $config = $this->createMock(CacheConfig::class);
        $config->method('getAccountId')->willReturn(self::ACCOUNT);
        $config->method('getApiToken')->willReturn($token);
        $config->method('getWorkerName')->willReturn('magento-fpc');
        $factory = $this->createMock(CurlAdapterFactory::class);
        $factory->method('create')->willReturn($client);
        return new CloudflareApi($config, $factory, new Json(), $logger ?? new NullLogger());
    }
}
