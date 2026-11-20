<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model\Worker;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\Worker\AdminLocation;
use SR\Cloudflare\Model\Worker\Deploy;
use SR\Cloudflare\Model\Worker\CacheEngine;
use SR\Cloudflare\Model\Worker\Credentials;
use SR\Cloudflare\Model\Worker\RoutePlanner;
use SR\Cloudflare\Model\Worker\Routes;
use SR\Cloudflare\Model\ZoneDirectory;

class DeployTest extends TestCase
{
    #[DataProvider('zoneSelections')]
    public function testDeploysOneWorkerToSelectedZonesAndDoesNotDuplicateRoutes(
        ?array $selection,
        array $expectedZones
    ): void {
        $workerPath = '/accounts/test/workers/scripts/magento-fpc';
        $availableZones = ['zone-a' => 'example.com', 'zone-b' => 'example.org'];
        $routes = [];
        $uploads = 0;
        $api = $this->createMock(CloudflareApi::class);
        $api->method('workerPath')->willReturn($workerPath);
        $api->method('getZones')->willReturn($availableZones);
        if ($expectedZones === []) {
            $api->expects(self::never())->method('request');
        }
        $api->method('request')->willReturnCallback(
            static function (string $method, string $path, ?array $body, bool $multipart) use (
                $workerPath,
                $expectedZones,
                &$routes,
                &$uploads
            ): array {
                if ($path === $workerPath . '/settings') {
                    throw new CloudflareApiException(__('Worker not found.'), 404);
                }
                if ($path === $workerPath) {
                    self::assertSame('PUT', $method);
                    self::assertTrue($multipart);
                    self::assertArrayHasKey('FPC-worker.js', $body);
                    $metadata = json_decode($body['metadata']->data, true, 512, JSON_THROW_ON_ERROR);
                    $bindings = array_column($metadata['bindings'], 'text', 'name');
                    self::assertSame('/runtime-only', $bindings['BYPASS_PATHS']);
                    self::assertArrayNotHasKey('ADMIN_PATH', $bindings);
                    $adminRoute = $routes['/zones/zone-a/workers/routes']['example.com/shop/control-panel*'] ?? null;
                    self::assertNotNull($adminRoute, 'The Magento Admin route must exist before Worker upload.');
                    self::assertArrayNotHasKey('script', $adminRoute);
                    $uploads++;
                    return [];
                }
                if ($path === $workerPath . '/subdomain') {
                    self::assertSame(['enabled' => false, 'previews_enabled' => false], $body);
                    return [];
                }
                self::assertContains($path, array_map(
                    static fn(string $id): string => '/zones/' . $id . '/workers/routes',
                    array_keys($expectedZones)
                ));
                if ($method === 'GET') {
                    return ['result' => array_values($routes[$path] ?? [])];
                }

                self::assertSame('POST', $method);
                self::assertArrayNotHasKey($body['pattern'], $routes[$path] ?? []);
                $route = ['id' => (string) (count($routes[$path] ?? []) + 1)] + $body;
                $routes[$path][$body['pattern']] = $route;
                return ['result' => $route];
            }
        );

        $config = $this->createMock(CacheConfig::class);
        $config->method('getAccountId')->willReturn('test');
        $config->method('getWorkerName')->willReturn('magento-fpc');
        $config->method('getSelectedZoneIds')->willReturn($selection);
        $config->method('getWorkerBypassPaths')->willReturn('/runtime-only');
        $config->method('getWorkerRouteBypassPaths')->willReturn('/route-only');
        $state = [];
        $deploy = $this->createDeployment($api, $config, $state);
        if ($expectedZones === []) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage($selection === []
                ? 'Select and save at least one available Cloudflare zone'
                : 'The Cloudflare token cannot access the following Zone IDs: zone-c.');
            $deploy->execute();
            return;
        }

        $result = $deploy->execute();
        self::assertSame($availableZones, $result['zones']);
        self::assertSame(array_values($expectedZones), array_keys($result['results']));
        self::assertSame(1, $uploads);
        $firstRoutes = $routes;
        $deploy->execute();
        self::assertSame(2, $uploads);
        self::assertSame($firstRoutes, $routes);
        self::assertArrayNotHasKey('script', $routes['/zones/zone-a/workers/routes']['example.com/shop/control-panel*']);
        foreach ($expectedZones as $id => $domain) {
            $zoneRoutes = $routes['/zones/' . $id . '/workers/routes'];
            self::assertSame('magento-fpc', $zoneRoutes['*.' . $domain . '/*']['script']);
            foreach ([$domain, '*.' . $domain] as $host) {
                self::assertArrayHasKey($host . '/route-only/*', $zoneRoutes);
                self::assertArrayNotHasKey('script', $zoneRoutes[$host . '/route-only/*']);
                self::assertArrayNotHasKey($host . '/runtime-only/*', $zoneRoutes);
            }
        }
    }

    public static function zoneSelections(): array
    {
        return [
            'all' => [null, ['zone-a' => 'example.com', 'zone-b' => 'example.org']],
            'subset' => [['zone-a'], ['zone-a' => 'example.com']],
            'none' => [[], []],
            'missing' => [['zone-c'], []],
        ];
    }

    public function testStopsUploadWhenMagentoAdminBypassCannotBeConfigured(): void
    {
        $workerPath = '/accounts/test/workers/scripts/magento-fpc';
        $routesPath = '/zones/zone-a/workers/routes';
        $api = $this->createMock(CloudflareApi::class);
        $api->method('workerPath')->willReturn($workerPath);
        $api->method('getZones')->willReturn(['zone-a' => 'example.com']);
        $requests = [];
        $api->expects(self::exactly(3))->method('request')->willReturnCallback(
            static function (string $method, string $path, ?array $body) use ($workerPath, &$requests): array {
                $requests[] = [$method, $path, $body];
                if ($path === $workerPath . '/settings') {
                    throw new CloudflareApiException(__('Worker not found.'), 404);
                }
                if ($method === 'GET') {
                    return ['result' => []];
                }
                throw new CloudflareApiException(__('Route creation failed.'), 403);
            }
        );
        $config = $this->createMock(CacheConfig::class);
        $config->method('getAccountId')->willReturn('test');
        $config->method('getWorkerName')->willReturn('magento-fpc');
        $state = [];

        $result = $this->createDeployment($api, $config, $state)->execute();

        self::assertSame([
            ['GET', $workerPath . '/settings', null],
            ['GET', $routesPath, null],
            ['POST', $routesPath, ['pattern' => 'example.com/shop/control-panel*']],
        ], $requests);
        self::assertStringContainsString('Could not configure the Magento Admin bypass in example.com.', $result['error']);
        self::assertSame([], $state['routes']);
    }

    #[DataProvider('routeCleanupCases')]
    public function testRemovesOnlyRoutesStillOwnedByMagento(
        bool $cleanupOnly,
        bool $failFirstAttempt,
        bool $hasUnmanagedBindings
    ): void {
        $workerPath = '/accounts/test/workers/scripts/magento-fpc';
        $excludedPath = '/zones/zone-b/workers/routes';
        $selectedPath = '/zones/zone-a/workers/routes';
        $owned = [
            'worker-route' => ['pattern' => 'example.org/*', 'script' => 'magento-fpc'],
            'bypass-route' => ['pattern' => 'example.org/admin*'],
            'edited-route' => ['pattern' => 'www.example.org/*', 'script' => 'magento-fpc'],
            'missing-route' => ['pattern' => 'old.example.org/*', 'script' => 'magento-fpc'],
        ];
        $routes = [
            'worker-route' => ['id' => 'worker-route'] + $owned['worker-route'],
            'bypass-route' => ['id' => 'bypass-route', 'script' => null] + $owned['bypass-route'],
            'edited-route' => ['id' => 'edited-route', 'script' => 'another-worker'] + $owned['edited-route'],
            'manual-route' => ['id' => 'manual-route', 'pattern' => 'shop.example.org/*', 'script' => 'magento-fpc'],
            'replacement-route' => ['id' => 'replacement-route'] + $owned['missing-route'],
        ];
        if (!$hasUnmanagedBindings) {
            unset($routes['manual-route'], $routes['replacement-route']);
        }
        $state = ['worker' => 'magento-fpc', 'routes' => ['zone-b' => $owned]];
        $uploads = 0;
        $failDelete = $failFirstAttempt;

        $config = $this->createMock(CacheConfig::class);
        $config->method('getAccountId')->willReturn('test');
        $config->method('getWorkerName')->willReturn('magento-fpc');
        $config->method('getSelectedZoneIds')->willReturn($cleanupOnly ? [] : ['zone-a']);
        $config->method('isZoneSelectionEmpty')->willReturn($cleanupOnly);
        $config->method('isCloudflareApplication')->willReturn(true);
        $api = $this->createMock(CloudflareApi::class);
        $api->method('workerPath')->willReturn($workerPath);
        $api->method('getZones')->willReturn(['zone-a' => 'example.com', 'zone-b' => 'example.org']);
        $api->method('request')->willReturnCallback(
            static function (string $method, string $path, ?array $body) use (
                $workerPath,
                $excludedPath,
                $selectedPath,
                &$routes,
                &$uploads,
                &$failDelete
            ): array {
                if ($method === 'GET') {
                    self::assertContains($path, [$selectedPath, $excludedPath]);
                    return ['result' => $path === $excludedPath ? array_values($routes) : []];
                }
                if ($method === 'DELETE') {
                    self::assertStringStartsWith($excludedPath . '/', $path);
                    $id = basename($path);
                    self::assertContains($id, ['worker-route', 'bypass-route']);
                    self::assertArrayHasKey($id, $routes);
                    if ($failDelete && $id === 'bypass-route') {
                        throw new CloudflareApiException(__('Route removal failed.'), 403);
                    }
                    unset($routes[$id]);
                    return [];
                }
                if ($path === $workerPath) {
                    self::assertSame('PUT', $method);
                    self::assertArrayNotHasKey('worker-route', $routes);
                    self::assertArrayNotHasKey('bypass-route', $routes);
                    $metadata = json_decode($body['metadata']->data, true, 512, JSON_THROW_ON_ERROR);
                    $bindings = array_column($metadata['bindings'], 'text', 'name');
                    self::assertSame('', $bindings['BYPASS_PATHS'] ?? null);
                    $uploads++;
                    return [];
                }

                self::assertSame('POST', $method);
                self::assertContains($path, [$workerPath . '/subdomain', $selectedPath]);
                return ['result' => ['id' => md5($body['pattern'] ?? '')]];
            }
        );
        $engine = $this->createMock(CacheEngine::class);
        $engine->expects($cleanupOnly && !$hasUnmanagedBindings ? self::once() : self::never())->method('useVarnish');
        $deploy = $this->createDeployment($api, $config, $state, cacheEngine: $engine);

        if ($failFirstAttempt) {
            $result = $deploy->execute();
            self::assertArrayHasKey('error', $result);
            self::assertStringContainsString('Route removal failed.', $result['results']['example.org']);
            self::assertSame(0, $uploads);
            self::assertArrayNotHasKey('worker-route', $state['routes']['zone-b']);
            self::assertArrayHasKey('bypass-route', $state['routes']['zone-b']);
            $failDelete = false;
        }

        $result = $deploy->execute();
        self::assertSame($cleanupOnly && $hasUnmanagedBindings, isset($result['error']));
        self::assertSame(
            $hasUnmanagedBindings ? ['edited-route', 'manual-route', 'replacement-route'] : ['edited-route'],
            array_keys($routes)
        );
        self::assertArrayNotHasKey('zone-b', $state['routes']);
        self::assertSame($hasUnmanagedBindings ? 'example.org' : null, $state['unmanaged_zones']['zone-b'] ?? null);
        self::assertSame($cleanupOnly ? 0 : 1, $uploads);
        self::assertSame(!$cleanupOnly, isset($result['results']['example.com']));
    }

    public static function routeCleanupCases(): array
    {
        return [
            'subset' => [false, false, true],
            'all deselected with manual bindings' => [true, false, true],
            'all deselected without manual bindings' => [true, false, false],
            'retry' => [false, true, true],
        ];
    }

    public function testKeepsTrackedRoutesWhenDeselectedZoneIsInaccessible(): void
    {
        $state = [
            'worker' => 'magento-fpc',
            'routes' => ['zone-b' => ['route-b' => ['pattern' => 'example.org/*', 'script' => 'magento-fpc']]],
        ];
        $originalState = $state;
        $config = $this->createMock(CacheConfig::class);
        $config->method('getAccountId')->willReturn('test');
        $config->method('getWorkerName')->willReturn('magento-fpc');
        $config->method('getSelectedZoneIds')->willReturn(['zone-a']);
        $api = $this->createMock(CloudflareApi::class);
        $api->method('getZones')->willReturn(['zone-a' => 'example.com']);
        $api->expects(self::never())->method('request');

        $this->expectExceptionMessage('The Cloudflare token cannot access the following Zone IDs: zone-b.');
        try {
            $this->createDeployment($api, $config, $state)->execute();
        } finally {
            self::assertSame($originalState, $state);
        }
    }

    public function testRequiresRouteCleanupBeforeRedeployingADeletedWorker(): void
    {
        $state = ['worker' => 'magento-fpc', 'worker_deleted' => true];
        $api = $this->createMock(CloudflareApi::class);
        $api->method('workerPath')->willReturn('/accounts/test/workers/scripts/magento-fpc');
        $api->expects(self::never())->method('getZones');
        $api->expects(self::never())->method('request');
        $config = $this->createMock(CacheConfig::class);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Retry Delete Worker to finish removing routes');
        $this->createDeployment($api, $config, $state)->execute();
    }

    public function testDoesNotModifyCloudflareWithoutACompletedCredentialsTest(): void
    {
        $state = [];
        $config = $this->createMock(CacheConfig::class);
        $api = $this->createMock(CloudflareApi::class);
        $api->method('getZones')->willReturn(['zone-a' => 'example.com']);
        $api->expects(self::never())->method('request');
        $api->expects(self::never())->method('verifyToken');
        $credentials = $this->createMock(Credentials::class);
        $credentials->expects(self::once())->method('canDeploy')->with(['zone-a' => 'example.com'])->willReturn(false);
        $engine = $this->createMock(CacheEngine::class);
        $engine->expects(self::never())->method('enableCloudflare');

        $this->expectExceptionMessage('Run Test Credentials');
        $this->createDeployment($api, $config, $state, $credentials, $engine)->execute();
    }

    public function testBlocksDeployWhenSelectAllLosesAccessToAnExistingZone(): void
    {
        $state = [
            'worker' => 'magento-fpc',
            'routes' => ['zone-b' => ['route-b' => ['pattern' => 'example.org/*', 'script' => 'magento-fpc']]],
        ];
        $config = $this->createMock(CacheConfig::class);
        $config->method('getSelectedZoneIds')->willReturn(null);
        $api = $this->createMock(CloudflareApi::class);
        $api->method('getZones')->willReturn(['zone-a' => 'example.com']);
        $api->expects(self::never())->method('request');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The Cloudflare token cannot access the following Zone IDs: zone-b.');
        $this->createDeployment($api, $config, $state)->execute();
    }

    #[DataProvider('initialDeploymentOutcomes')]
    public function testSwitchesOnlyAfterAllRoutesAndRollsBackAnIncompleteFirstActivation(bool $fail): void
    {
        $state = [];
        $routes = [];
        $uploaded = false;
        $workerPath = '/accounts/test/workers/scripts/magento-fpc';
        $config = $this->createMock(CacheConfig::class);
        $config->method('getWorkerName')->willReturn('magento-fpc');
        $api = $this->createMock(CloudflareApi::class);
        $api->method('workerPath')->willReturn($workerPath);
        $api->method('getZones')->willReturn(['zone-a' => 'example.com', 'zone-b' => 'example.org']);
        $api->method('request')->willReturnCallback(
            static function (string $method, string $path, ?array $body) use ($workerPath, $fail, &$routes, &$uploaded): array {
                if ($path === $workerPath . '/settings') {
                    throw new CloudflareApiException(__('Worker not found.'), 404);
                }
                if ($path === $workerPath) {
                    $uploaded = true;
                    return [];
                }
                if ($path === $workerPath . '/subdomain') {
                    return [];
                }
                if ($method === 'GET') {
                    return ['result' => array_values($routes[$path] ?? [])];
                }
                if ($method === 'DELETE') {
                    unset($routes[dirname($path)][basename($path)]);
                    return [];
                }
                if (isset($body['script'])) {
                    self::assertTrue($uploaded);
                    if ($fail && $path === '/zones/zone-b/workers/routes') {
                        throw new CloudflareApiException(__('Could not create route.'), 403);
                    }
                }
                $id = md5($body['pattern']);
                return ['result' => $routes[$path][$id] = ['id' => $id] + $body];
            }
        );
        $engine = $this->createMock(CacheEngine::class);
        $engine->expects($fail ? self::never() : self::once())->method('enableCloudflare')->willReturnCallback(
            static function () use (&$routes): void {
                foreach ($routes as $zoneRoutes) {
                    self::assertCount(2, array_column($zoneRoutes, 'script'));
                }
            }
        );

        $result = $this->createDeployment($api, $config, $state, cacheEngine: $engine)->execute();

        self::assertSame($fail, isset($result['error']));
        foreach ($routes as $zoneRoutes) {
            self::assertCount($fail ? 0 : 2, array_column($zoneRoutes, 'script'));
        }
    }

    public static function initialDeploymentOutcomes(): array
    {
        return ['success' => [false], 'one zone fails' => [true]];
    }

    private function createDeployment(
        CloudflareApi $api,
        CacheConfig $config,
        array &$state,
        ?Credentials $credentials = null,
        ?CacheEngine $cacheEngine = null
    ): Deploy {
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://example.com/shop/control-panel/srcloudflare/worker/deploy/');
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->method('lock')->willReturn(true);
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(static function () use (&$state): array {
            return $state;
        });
        $flags->method('saveFlag')->willReturnCallback(static function (string $key, array $data) use (&$state): bool {
            if (!str_starts_with($key, 'sr_cf_zones_')) {
                $state = $data;
            }
            return true;
        });
        if ($credentials === null) {
            $credentials = $this->createMock(Credentials::class);
            $credentials->method('canDeploy')->willReturn(true);
        }
        $api->method('getActiveDeployment')->willReturn(['versions' => ['version'], 'deployedAt' => '2026-01-01']);

        return new Deploy(
            $api,
            new ZoneDirectory($config, $api, $flags),
            $config,
            new AdminLocation(
                $url,
                $this->createMock(ScopeConfigInterface::class),
                $this->createMock(StoreManagerInterface::class)
            ),
            new RoutePlanner(),
            new Routes($api, $flags),
            $lock,
            $flags,
            new Json(),
            $credentials,
            $cacheEngine ?? $this->createMock(CacheEngine::class)
        );
    }
}
