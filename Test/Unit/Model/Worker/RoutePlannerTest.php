<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model\Worker;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Model\Worker\RoutePlanner;

class RoutePlannerTest extends TestCase
{
    #[DataProvider('storedBypassPaths')]
    public function testBuildsWorkerAndBypassRoutesForApexAndSubdomains(string $storedPaths): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['srcloudflare/worker_routes/bypass_paths', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null, $storedPaths],
            ['srcloudflare/worker/bypass_paths', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null, "/runtime\n/cache"],
        ]);
        $config = new CacheConfig(
            $scopeConfig,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateInterface::class)
        );
        $routes = (new RoutePlanner())->plan(
            'example.com',
            'magento-fpc',
            'example.com',
            '/shop/control-panel',
            false,
            $config->getWorkerRouteBypassPaths()
        );

        self::assertSame('/runtime,/cache', $config->getWorkerBypassPaths());
        self::assertSame('magento-fpc', $routes['example.com/*']);
        self::assertSame('magento-fpc', $routes['*.example.com/*']);
        self::assertArrayHasKey('example.com/shop/control-panel*', $routes);
        self::assertNull($routes['example.com/shop/control-panel*']);
        $expectedPatterns = [
            '/static/*', '/media/*', '/pub/static/*', '/pub/media/*', '/cdn-cgi/*', '/rest/*', '/api/*', '/feeds/*',
        ];
        foreach (['example.com', '*.example.com'] as $host) {
            foreach ($expectedPatterns as $pattern) {
                self::assertArrayHasKey($host . $pattern, $routes);
                self::assertNull($routes[$host . $pattern]);
            }
        }
        self::assertArrayNotHasKey('*.example.com/customer/*', $routes);
    }

    public static function storedBypassPaths(): array
    {
        return [
            'existing comma-separated paths' => ['/api,/feeds'],
            'paths on separate lines' => ["/api\r\n/feeds"],
        ];
    }

    public function testBypassesDedicatedAdminHostnameOnlyInItsZone(): void
    {
        $planner = new RoutePlanner();
        $routes = $planner->plan('example.com', 'magento-fpc', 'admin.example.com', '/control', true, '');
        self::assertArrayHasKey('admin.example.com/*', $routes);
        self::assertNull($routes['admin.example.com/*']);
        self::assertArrayNotHasKey(
            'admin.example.com/*',
            $planner->plan('example.org', 'magento-fpc', 'admin.example.com', '/control', true, '')
        );
    }

    public function testDoesNotAllowBypassingTheLogoutHandler(): void
    {
        $this->expectException(LocalizedException::class);
        (new RoutePlanner())->plan('example.com', 'magento-fpc', 'example.com', '/admin', false, '/customer');
    }
}
