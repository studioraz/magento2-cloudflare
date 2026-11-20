<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model\Worker;

use Magento\Config\App\Config\Type\System;
use Magento\Deploy\Model\DeploymentConfig\ChangeDetector;
use Magento\Deploy\Model\DeploymentConfig\Hash;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\DeploymentConfig\Writer;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use Magento\PageCache\Model\System\Config\Source\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Model\Worker\CacheEngine;

class CacheEngineTest extends TestCase
{
    #[DataProvider('transitions')]
    public function testSynchronizesOnlyTheSystemConfigurationAfterWritingTheFpcKey(
        bool $activate,
        int $from,
        ?int $to,
        bool $pendingChanges = false
    ): void {
        $files = $this->createMock(ConfigFilePool::class);
        $files->method('getPaths')->willReturn([ConfigFilePool::APP_ENV => 'env.php']);
        $directory = $this->createMock(WriteInterface::class);
        $directory->expects($to !== null || $pendingChanges ? self::once() : self::never())->method('isWritable')
            ->with('env.php')->willReturn(true);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->with(DirectoryList::CONFIG)->willReturn($directory);
        $writer = $this->createMock(Writer::class);
        $envWritten = false;
        $writer->expects($to === null ? self::never() : self::once())->method('saveConfig')
            ->with([
                ConfigFilePool::APP_ENV => [
                    'system' => ['default' => ['system' => ['full_page_cache' => ['caching_application' => $to]]]],
                ],
            ], false, null, [], true)->willReturnCallback(static function () use (&$envWritten): void {
                $envWritten = true;
            });
        $changeDetector = $this->createMock(ChangeDetector::class);
        $changeDetector->expects($to !== null || $pendingChanges ? self::once() : self::never())->method('hasChanges')
            ->with(System::CONFIG_TYPE)->willReturn($pendingChanges);
        $hash = $this->createMock(Hash::class);
        $hashUpdated = false;
        $hash->expects($to === null ? self::never() : self::once())->method('regenerate')
            ->with(System::CONFIG_TYPE)->willReturnCallback(static function () use (&$envWritten, &$hashUpdated): void {
                self::assertTrue($envWritten);
                $hashUpdated = true;
            });
        $config = $this->createMock(ReinitableConfigInterface::class);
        $currentType = $from;
        $config->method('getValue')->with(PageCacheConfig::XML_PAGECACHE_TYPE)->willReturnCallback(
            static function () use (&$currentType): int {
                return $currentType;
            }
        );
        $config->expects($to === null ? self::never() : self::once())->method('reinit')->willReturnCallback(
            static function () use ($config, $to, &$currentType, &$hashUpdated): ReinitableConfigInterface {
                self::assertTrue($hashUpdated);
                $currentType = $to;
                return $config;
            }
        );
        $service = new CacheEngine(
            $this->createMock(AuthorizationInterface::class),
            $files,
            $filesystem,
            $writer,
            $config,
            $this->createMock(PageCacheConfig::class),
            $this->createMock(Application::class),
            $changeDetector,
            $hash
        );

        if ($pendingChanges) {
            $this->expectException(LocalizedException::class);
            $this->expectExceptionMessage('Run app:config:import');
        }
        if ($activate) {
            $service->enableCloudflare();
        } else {
            $service->useVarnish();
        }
    }

    public static function transitions(): array
    {
        return [
            'deploy' => [true, PageCacheConfig::VARNISH, CacheConfig::CLOUDFLARE],
            'delete' => [false, CacheConfig::CLOUDFLARE, PageCacheConfig::VARNISH],
            'delete while built-in is active' => [false, PageCacheConfig::BUILT_IN, null],
            'existing system changes require import' => [true, PageCacheConfig::VARNISH, null, true],
        ];
    }
}
