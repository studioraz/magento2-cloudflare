<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model\Worker;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Model\Worker\AdminLocation;
use SR\Cloudflare\Model\Worker\RoutePlanner;

class AdminLocationTest extends TestCase
{
    #[DataProvider('adminHosts')]
    public function testUsesTheConfiguredAdminPrefixOrDedicatedHostname(
        bool $customUrl,
        string $storeHost,
        string $expectedPattern
    ): void {
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::once())->method('getUrl')
            ->with('srcloudflare/worker/deploy', ['_nosecret' => true])
            ->willReturn('https://ADMIN.example.com/shop/control-panel/srcloudflare/worker/deploy/?fixture=1');
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->with('admin/url/use_custom')->willReturn($customUrl);
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://' . $storeHost . '/');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStores')->willReturn([$store]);

        $location = (new AdminLocation($url, $scope, $stores))->resolve();

        self::assertSame($expectedPattern, RoutePlanner::formatAdminBypassPattern(...$location));
        self::assertSame($expectedPattern, (new RoutePlanner())->getAdminBypassPattern('example.com', ...$location));
    }

    public static function adminHosts(): array
    {
        return [
            'default backend URL' => [false, 'shop.example.com', 'admin.example.com/shop/control-panel*'],
            'custom URL on a storefront host' => [true, 'admin.example.com', 'admin.example.com/shop/control-panel*'],
            'dedicated custom hostname' => [true, 'shop.example.com', 'admin.example.com/*'],
        ];
    }

    public function testRejectsAnAdminUrlWithoutABackendPath(): void
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://example.com/srcloudflare/worker/deploy');
        $location = new AdminLocation(
            $url,
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(StoreManagerInterface::class)
        );

        $this->expectException(LocalizedException::class);
        $location->resolve();
    }
}
