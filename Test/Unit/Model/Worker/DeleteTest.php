<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model\Worker;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\Worker\Delete;
use SR\Cloudflare\Model\Worker\CacheEngine;
use SR\Cloudflare\Model\Worker\Routes;
use SR\Cloudflare\Model\ZoneDirectory;

class DeleteTest extends TestCase
{
    private const WORKER = 'magento-fpc';
    private const ACCOUNT = 'test-account';
    private const WORKER_PATH = '/accounts/test-account/workers/scripts/magento-fpc';

    public function testDeletesManagedWorkerEvenWithManualRoutesAndRetriesOnlyRemainingCleanup(): void
    {
        $routePath = '/zones/previously-selected-zone/workers/routes';
        $route = ['id' => 'bypass', 'pattern' => 'example.com/admin*'];
        $managedRoute = ['id' => 'managed', 'pattern' => 'example.com/*', 'script' => self::WORKER];
        $manualRoute = ['id' => 'manual', 'pattern' => 'www.example.com/*', 'script' => self::WORKER];
        $repurposedRoute = ['id' => 'modified', 'pattern' => 'example.com/media/*', 'script' => 'another-worker'];
        $state = [
            'worker' => self::WORKER,
            'routes' => ['previously-selected-zone' => [
                'bypass' => $route,
                'managed' => $managedRoute,
                'modified' => ['pattern' => $repurposedRoute['pattern']],
            ]],
        ];
        $steps = [
            ['DELETE', self::WORKER_PATH, []],
            ['GET', $routePath, ['result' => [$route, $managedRoute, $manualRoute, $repurposedRoute]]],
            ['DELETE', $routePath . '/bypass', []],
            ['DELETE', $routePath . '/managed', null],
            ['GET', $routePath, ['result' => [$managedRoute, $manualRoute, $repurposedRoute]]],
            ['DELETE', $routePath . '/managed', []],
        ];
        $api = $this->createMock(CloudflareApi::class);
        $api->method('workerPath')->willReturn(self::WORKER_PATH);
        $api->expects(self::exactly(count($steps)))->method('request')->willReturnCallback(
            static function (string $method, string $path) use (&$steps): array {
                [$expectedMethod, $expectedPath, $response] = array_shift($steps);
                self::assertSame([$expectedMethod, $expectedPath], [$method, $path]);
                if ($response === null) {
                    throw new CloudflareApiException(__('Cloudflare temporarily unavailable.'), 503);
                }
                return $response;
            }
        );
        $cacheEngine = $this->createMock(CacheEngine::class);
        $cacheEngine->method('isAllowed')->willReturn(true);
        $cacheEngine->expects(self::exactly(2))->method('useVarnish')->willReturnCallback(
            static function () use (&$state): void {
                self::assertTrue($state['worker_deleted']);
            }
        );
        $delete = $this->createDelete($api, $state, $cacheEngine);

        self::assertTrue($delete->canDelete());
        $first = $delete->execute(self::ACCOUNT, self::WORKER);
        self::assertArrayHasKey('error', $first);
        self::assertSame(['previously-selected-zone' => 'Cloudflare temporarily unavailable.'], $first['results']);
        self::assertTrue($state['worker_deleted']);
        self::assertArrayNotHasKey('bypass', $state['routes']['previously-selected-zone']);

        self::assertArrayHasKey('message', $delete->execute(self::ACCOUNT, self::WORKER));
        self::assertFalse($delete->canDelete());
        self::assertSame([], $state);
    }

    public function testClearsOwnershipIfTheWorkerIsAlreadyAbsent(): void
    {
        $state = ['worker' => self::WORKER, 'routes' => []];
        $api = $this->createMock(CloudflareApi::class);
        $api->method('workerPath')->willReturn(self::WORKER_PATH);
        $api->expects(self::once())->method('request')->with('DELETE', self::WORKER_PATH)
            ->willThrowException(new CloudflareApiException(__('Worker not found.'), 404));

        self::assertArrayHasKey('message', $this->createDelete($api, $state)->execute(self::ACCOUNT, self::WORKER));
        self::assertSame([], $state);
    }

    public function testChecksWorkerAbsenceAfterDeleteTimeoutBeforeSwitching(): void
    {
        $state = ['worker' => self::WORKER, 'routes' => []];
        $api = $this->createMock(CloudflareApi::class);
        $api->method('workerPath')->willReturn(self::WORKER_PATH);
        $api->expects(self::exactly(2))->method('request')->willReturnCallback(
            static function (string $method, string $path): never {
                if ($method === 'DELETE') {
                    self::assertSame(self::WORKER_PATH, $path);
                    throw new CloudflareApiException(__('Connection timed out.'));
                }
                self::assertSame('GET', $method);
                self::assertSame(self::WORKER_PATH . '/settings', $path);
                throw new CloudflareApiException(__('Worker not found.'), 404);
            }
        );
        $engine = $this->createMock(CacheEngine::class);
        $engine->expects(self::once())->method('useVarnish');

        self::assertArrayHasKey('message', $this->createDelete($api, $state, $engine)->execute(self::ACCOUNT, self::WORKER));
        self::assertSame([], $state);
    }

    public function testLeavesManagedRoutesInPlaceWhenCloudflareRefusesToDeleteTheWorker(): void
    {
        $original = ['worker' => self::WORKER, 'routes' => ['zone' => ['route' => ['pattern' => 'example.com/*']]]];
        $state = $original;
        $api = $this->createMock(CloudflareApi::class);
        $api->method('workerPath')->willReturn(self::WORKER_PATH);
        $api->expects(self::once())->method('request')->with('DELETE', self::WORKER_PATH)
            ->willThrowException(new CloudflareApiException(__('Forbidden.'), 403));

        $this->expectException(CloudflareApiException::class);
        $this->expectExceptionCode(403);
        try {
            $this->createDelete($api, $state)->execute(self::ACCOUNT, self::WORKER);
        } finally {
            self::assertSame($original, $state);
        }
    }

    #[DataProvider('invalidTargets')]
    public function testRefusesToDeleteAnUnmanagedOrStaleTarget(array $state, string $account, string $worker): void
    {
        $api = $this->createMock(CloudflareApi::class);
        $api->expects(self::never())->method('request');

        $this->expectException(LocalizedException::class);
        $this->createDelete($api, $state)->execute($account, $worker);
    }

    public static function invalidTargets(): array
    {
        return [
            'unmanaged Worker' => [[], self::ACCOUNT, self::WORKER],
            'different account' => [['worker' => self::WORKER], 'old-account', self::WORKER],
            'different Worker' => [['worker' => self::WORKER], self::ACCOUNT, 'old-worker'],
        ];
    }

    private function createDelete(CloudflareApi $api, array &$state, ?CacheEngine $cacheEngine = null): Delete
    {
        $config = $this->createMock(CacheConfig::class);
        $config->method('getAccountId')->willReturn(self::ACCOUNT);
        $config->method('getWorkerName')->willReturn(self::WORKER);
        $directory = new ZoneDirectory($config, $api, $this->createMock(FlagManager::class));
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->with(CacheEngine::OPERATION_LOCK, 0)->willReturn(true);
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->with($directory->getDeploymentKey())->willReturnCallback(
            static function () use (&$state): array {
                return $state;
            }
        );
        $flags->method('saveFlag')->willReturnCallback(static function (string $key, array $data) use (&$state): bool {
            $state = $data;
            return true;
        });
        $flags->method('deleteFlag')->with($directory->getDeploymentKey())->willReturnCallback(
            static function () use (&$state): bool {
                $state = [];
                return true;
            }
        );

        return new Delete(
            $api,
            $config,
            $directory,
            new Routes($api, $flags),
            $lock,
            $flags,
            $cacheEngine ?? $this->createMock(CacheEngine::class)
        );
    }
}
