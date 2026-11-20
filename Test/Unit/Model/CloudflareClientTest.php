<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\CloudflareClient;
use SR\Cloudflare\Model\ZoneDirectory;
use SR\Gateway\Api\LoggerInterface;

class CloudflareClientTest extends TestCase
{
    private const FIRST_ZONE = '11111111111111111111111111111111';
    private const SECOND_ZONE = '22222222222222222222222222222222';

    #[DataProvider('zoneSelections')]
    public function testPurgesSelectedZonesAndZonesWithManagedRoutes(
        ?string $selection,
        array $managedRoutes,
        array $expectedZoneIds
    ): void
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnMap([
            ['srcloudflare/cache/zone_selection', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null, $selection],
        ]);
        $config = $this->getMockBuilder(CacheConfig::class)
            ->setConstructorArgs([
                $scope,
                $this->createMock(StoreManagerInterface::class),
                $this->createMock(StateInterface::class),
            ])
            ->onlyMethods(['getAccountId', 'getApiToken', 'isConfigured'])
            ->getMock();
        $config->method('getAccountId')->willReturn('account');
        $config->method('isConfigured')->willReturn(true);
        $api = $this->createMock(CloudflareApi::class);
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnMap([
            [
                'sr_cf_zones_' . substr(hash('sha256', 'account:'), 0, 32),
                [self::FIRST_ZONE => 'example.com', self::SECOND_ZONE => 'example.org'],
            ],
            [
                'sr_cf_' . substr(hash('sha256', 'account'), 0, 32),
                ['worker' => 'magento-fpc', 'routes' => $managedRoutes],
            ],
        ]);

        $queuedZoneIds = array_intersect([self::FIRST_ZONE, self::SECOND_ZONE], $expectedZoneIds);
        $expectedRequests = array_merge($expectedZoneIds, $queuedZoneIds);
        $requestNumber = 0;
        $api->expects(self::exactly(count($expectedRequests)))->method('request')->willReturnCallback(
            static function (string $method, string $path, array $body) use ($expectedRequests, &$requestNumber): array {
                self::assertSame('POST', $method);
                self::assertSame('/zones/' . $expectedRequests[$requestNumber++] . '/purge_cache', $path);
                self::assertSame(['tags' => ['cat_p_42']], $body);
                return [];
            }
        );
        $client = new CloudflareClient(
            $config,
            $api,
            new ZoneDirectory($config, $api, $flags),
            $this->createMock(LoggerInterface::class)
        );

        $client->purgeByTags(['cat_p_42']);
        $client->purgeByType('tags', ['cat_p_42'], self::FIRST_ZONE);
        $client->purgeByType('tags', ['cat_p_42'], self::SECOND_ZONE);
    }

    public static function zoneSelections(): array
    {
        $workerRoute = ['worker-route' => ['pattern' => 'example.com/*', 'script' => 'magento-fpc']];
        $bypassRoute = ['bypass-route' => ['pattern' => 'example.com/admin*']];

        return [
            'default' => [null, [], [self::FIRST_ZONE, self::SECOND_ZONE]],
            'all' => ['all', [], [self::FIRST_ZONE, self::SECOND_ZONE]],
            'subset' => [self::SECOND_ZONE, [], [self::SECOND_ZONE]],
            'selected and pending cleanup' => [
                self::SECOND_ZONE,
                [self::FIRST_ZONE => $workerRoute, self::SECOND_ZONE => $workerRoute],
                [self::SECOND_ZONE, self::FIRST_ZONE],
            ],
            'all deselected with route cleanup pending' => ['none', [self::FIRST_ZONE => $workerRoute], [self::FIRST_ZONE]],
            'partial cleanup' => [self::SECOND_ZONE, [self::FIRST_ZONE => $bypassRoute], [self::SECOND_ZONE, self::FIRST_ZONE]],
            'cleanup completed' => [self::SECOND_ZONE, [self::FIRST_ZONE => []], [self::SECOND_ZONE]],
            'none' => ['none', [], []],
            'malformed' => ['invalid', [], []],
        ];
    }
}
