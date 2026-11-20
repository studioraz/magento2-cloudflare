<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model\Worker;

use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\PurgeQueue\QueueRepository;
use SR\Cloudflare\Model\Worker\CacheEngine;
use SR\Cloudflare\Model\Worker\Credentials;
use SR\Cloudflare\Model\Worker\ForgetZone;
use SR\Cloudflare\Model\ZoneDirectory;

class ForgetZoneTest extends TestCase
{
    private const ACCOUNT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const KEPT = '11111111111111111111111111111111';
    private const DELETED = '22222222222222222222222222222222';

    #[DataProvider('zoneCases')]
    public function testForgettingRequiresAnUnavailableZoneAndUpdatesLocalState(
        ?array $selection,
        array $availableZones,
        ?string $savedSelection,
        bool $lockedSelection = false,
        bool $queueFails = false
    ): void {
        $zoneWasAvailable = isset($availableZones[self::DELETED]);
        $canRemove = !$zoneWasAvailable && !$lockedSelection;
        $keptRoute = ['pattern' => 'example.com/*', 'script' => 'magento-fpc'];
        $deletedRoute = ['pattern' => 'example.org/*', 'script' => 'magento-fpc'];
        $state = [
            'worker' => 'magento-fpc',
            'cache_namespace' => 'current-namespace',
            'routes' => [self::KEPT => ['active' => $keptRoute], self::DELETED => ['removed' => $deletedRoute]],
            'unmanaged_zones' => [self::DELETED => 'example.org'],
        ];
        $config = $this->createMock(CacheConfig::class);
        $config->method('getAccountId')->willReturn(self::ACCOUNT);
        $config->method('getSelectedZoneIds')->willReturnCallback(static function () use (&$selection): ?array {
            return $selection;
        });
        $api = $this->createMock(CloudflareApi::class);
        $api->expects(self::once())->method('getZones')->willReturn($availableZones);
        $api->expects(self::never())->method('request');
        $flags = $this->createMock(FlagManager::class);
        $directory = new ZoneDirectory($config, $api, $flags);
        $key = $directory->getDeploymentKey();
        $flags->method('getFlagData')->willReturnCallback(
            static function (string $code) use (&$state, $key, $availableZones): array {
                return $code === $key ? $state : $availableZones;
            }
        );
        $flags->method('saveFlag')->willReturnCallback(
            static function (string $code, array $data) use (&$state, $key): bool {
                if ($code === $key) {
                    $state = $data;
                }
                return true;
            }
        );
        $flags->expects($canRemove && !$queueFails ? self::once() : self::never())
            ->method('deleteFlag')->with($key . '_verified');
        $configWriter = $this->createMock(WriterInterface::class);
        if ($savedSelection === null) {
            $configWriter->expects(self::never())->method('save');
        } else {
            $configWriter->expects(self::once())->method('save')->with(CacheConfig::ZONE_SELECTION_PATH, $savedSelection);
        }
        $systemConfig = $this->createMock(ReinitableConfigInterface::class);
        $systemConfig->expects($savedSelection !== null && !$queueFails ? self::once() : self::never())->method('reinit')
            ->willReturnCallback(static function () use (&$selection, $savedSelection, $systemConfig) {
                $selection = $savedSelection === 'none' ? [] : [$savedSelection];
                return $systemConfig;
            });
        $queue = $this->createMock(QueueRepository::class);
        $queue->expects($canRemove ? self::once() : self::never())->method('withWriteLock')
            ->willReturnCallback(static fn(callable $operation) => $operation());
        $queue->expects($canRemove ? self::once() : self::never())->method('deleteForZone')->with(self::DELETED)
            ->willReturnCallback(static function () use ($queueFails): int {
                if ($queueFails) {
                    throw new LocalizedException(__('Unable to delete purge jobs.'));
                }
                return 2;
            });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($canRemove ? self::once() : self::never())->method('beginTransaction');
        $connection->expects($canRemove && !$queueFails ? self::once() : self::never())->method('commit');
        $connection->expects($queueFails ? self::once() : self::never())->method('rollBack');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $lock = $this->createMock(LockManagerInterface::class);
        $lock->expects(self::once())->method('lock')->with(CacheEngine::OPERATION_LOCK, 0)->willReturn(true);
        $lock->expects(self::once())->method('unlock')->with(CacheEngine::OPERATION_LOCK);
        $settingChecker = $this->createMock(SettingChecker::class);
        $settingChecker->method('isReadOnly')->willReturn($lockedSelection);
        $service = new ForgetZone(
            $config,
            $directory,
            new Credentials($config, $api, $directory, $flags),
            $flags,
            $configWriter,
            $systemConfig,
            $settingChecker,
            $queue,
            $resource,
            $lock
        );

        if ($zoneWasAvailable) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage('is available in Cloudflare or is no longer tracked');
            $service->execute(self::ACCOUNT, self::DELETED);
            return;
        }
        if ($lockedSelection || $queueFails) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage($lockedSelection ? 'locked zone selection' : 'Unable to delete purge jobs.');
            $service->execute(self::ACCOUNT, self::DELETED);
            return;
        }

        $result = $service->execute(self::ACCOUNT, self::DELETED);
        self::assertSame($savedSelection ?? 'all', $result['selection']);
        self::assertSame([], $result['unavailableZoneIds']);
        self::assertSame(['worker' => 'magento-fpc', 'cache_namespace' => 'current-namespace',
            'routes' => [self::KEPT => ['active' => $keptRoute]], 'unmanaged_zones' => []], $state);
        self::assertSame([self::KEPT], $directory->getPurgeZoneIds());
        self::assertFalse($directory->shouldPurgeZone(self::DELETED));
    }

    public static function zoneCases(): array
    {
        $available = [self::KEPT => 'example.com'];
        return [
            'select all' => [null, $available, null],
            'explicit selection' => [[self::KEPT, self::DELETED], $available, self::KEPT],
            'still available' => [null, $available + [self::DELETED => 'example.org'], null],
            'locked selection' => [[self::KEPT, self::DELETED], $available, null, true],
            'queue cleanup fails' => [[self::KEPT, self::DELETED], $available, self::KEPT, false, true],
        ];
    }
}
