<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model\Worker;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\Worker\Credentials;
use SR\Cloudflare\Model\ZoneDirectory;

class CredentialsTest extends TestCase
{
    #[DataProvider('purgeOutcomes')]
    public function testTestsSavedZonesAndBindsResultToTokenAndZoneSelection(bool $fails): void
    {
        $selected = ['zone-a'];
        $token = 'test-token';
        $record = null;
        $zones = ['zone-a' => 'example.com', 'zone-b' => 'example.org'];
        $config = $this->createMock(CacheConfig::class);
        $config->method('getSelectedZoneIds')->willReturnCallback(static function () use (&$selected): array {
            return $selected;
        });
        $config->method('getApiToken')->willReturnCallback(static function () use (&$token): string {
            return $token;
        });
        $api = $this->createMock(CloudflareApi::class);
        $api->expects(self::once())->method('verifyToken');
        $api->method('getZones')->willReturn($zones);
        $api->expects(self::once())->method('request')->willReturnCallback(
            static function (string $method, string $path, array $body) use ($fails): array {
                self::assertSame('POST', $method);
                self::assertSame('/zones/zone-a/purge_cache', $path);
                self::assertMatchesRegularExpression('/^sr-cloudflare-test-[a-f0-9]{32}$/', $body['tags'][0]);
                if ($fails) {
                    throw new CloudflareApiException(__('Forbidden.'), 403);
                }
                return [];
            }
        );
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(static function () use (&$record): ?array {
            return $record;
        });
        $flags->expects($fails ? self::never() : self::once())->method('saveFlag')->willReturnCallback(
            static function (string $code, array $value) use (&$record): bool {
                $record = $value;
                return true;
            }
        );
        $service = new Credentials($config, $api, new ZoneDirectory($config, $api, $this->createMock(FlagManager::class)), $flags);

        if ($fails) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage('Could not verify Cache Purge in example.com.');
            $service->verify();
            return;
        }

        self::assertSame($zones, $service->verify());
        self::assertTrue($service->canDeploy(['zone-a' => 'example.com']));
        $selected = ['zone-a', 'zone-b'];
        self::assertFalse($service->canDeploy($zones));
        self::assertFalse($service->canDeploy(['zone-a' => 'example.com']));
        $selected = ['zone-a'];
        $token = 'rotated-token';
        self::assertFalse($service->canDeploy(['zone-a' => 'example.com']));
    }

    public static function purgeOutcomes(): array
    {
        return ['accepted' => [false], 'forbidden' => [true]];
    }

    #[DataProvider('inaccessibleZoneSelections')]
    public function testNamesInaccessibleZonesBeforeCheckingPurge(?array $selection, array $routes): void
    {
        $availableZones = ['zone-a' => 'example.com'];
        $config = $this->createMock(CacheConfig::class);
        $config->method('getSelectedZoneIds')->willReturn($selection);
        $config->method('getApiToken')->willReturn('test-token');
        $api = $this->createMock(CloudflareApi::class);
        $api->method('getZones')->willReturn($availableZones);
        $api->expects(self::never())->method('request');
        $directoryFlags = $this->createMock(FlagManager::class);
        $directoryFlags->method('getFlagData')->willReturn(['routes' => $routes]);
        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturn([
            'token_hash' => hash('sha256', 'test-token'),
            'zones' => ['zone-a', 'zone-b'],
        ]);
        $flags->expects(self::once())->method('deleteFlag');
        $flags->expects(self::never())->method('saveFlag');
        $service = new Credentials($config, $api, new ZoneDirectory($config, $api, $directoryFlags), $flags);

        self::assertTrue($service->canDeploy($availableZones + ['zone-b' => 'example.org']));
        self::assertFalse($service->canDeploy($availableZones));
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The Cloudflare token cannot access the following Zone IDs: zone-b.');
        $service->verify();
    }

    public static function inaccessibleZoneSelections(): array
    {
        return [
            'explicit selection' => [['zone-a', 'zone-b'], []],
            'Select All with a previously deployed zone' => [
                null,
                ['zone-b' => ['route-b' => ['pattern' => 'example.org/*', 'script' => 'magento-fpc']]],
            ],
        ];
    }
}
