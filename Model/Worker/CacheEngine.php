<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Worker;

use Magento\Config\App\Config\Type\System;
use Magento\Deploy\Model\DeploymentConfig\ChangeDetector;
use Magento\Deploy\Model\DeploymentConfig\Hash;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\DeploymentConfig\Writer;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\PageCache\Model\Config as PageCacheConfig;
use Magento\PageCache\Model\System\Config\Source\Application;
use SR\Cloudflare\Config\CacheConfig;

class CacheEngine
{
    public const SYSTEM_RESOURCE = 'Magento_Config::config_system';
    public const OPERATION_LOCK = 'sr_cloudflare_fpc_worker_operation';

    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly ConfigFilePool $configFiles,
        private readonly Filesystem $filesystem,
        private readonly Writer $writer,
        private readonly ReinitableConfigInterface $config,
        private readonly PageCacheConfig $pageCacheConfig,
        private readonly Application $application,
        private readonly ChangeDetector $changeDetector,
        private readonly Hash $configHash
    ) {
    }

    public function getLabel(): string
    {
        $engine = $this->application->toArray()[$this->pageCacheConfig->getType()] ?? __('Unknown');
        return (string) ($this->pageCacheConfig->isEnabled() ? $engine : __('%1 (Magento FPC disabled)', $engine));
    }

    public function isAllowed(): bool
    {
        return $this->authorization->isAllowed(self::SYSTEM_RESOURCE);
    }

    public function assertAllowed(): void
    {
        if (!$this->isAllowed()) {
            throw new AuthorizationException(__('You need permission to manage Magento System configuration.'));
        }
    }

    public function assertWritable(): void
    {
        $path = $this->configFiles->getPaths()[ConfigFilePool::APP_ENV];
        if (!$this->filesystem->getDirectoryWrite(DirectoryList::CONFIG)->isWritable($path)) {
            throw new LocalizedException(__('Magento cannot write app/etc/env.php to switch the cache application.'));
        }

        $override = json_decode((string) getenv('MAGENTO_DC__OVERRIDE'), true);
        $variable = 'CONFIG__DEFAULT__SYSTEM__FULL_PAGE_CACHE__CACHING_APPLICATION';
        if (isset($_ENV[$variable])
            || getenv($variable) !== false
            || getenv('MAGENTO_DC_SYSTEM__DEFAULT__SYSTEM__FULL_PAGE_CACHE__CACHING_APPLICATION') !== false
            || isset($override['system']['default']['system']['full_page_cache']['caching_application'])
        ) {
            throw new LocalizedException(__('The cache application is locked by a process environment variable.'));
        }

        if ($this->changeDetector->hasChanges(System::CONFIG_TYPE)) {
            throw new LocalizedException(
                __('Run app:config:import to synchronize existing system configuration changes before switching the cache application.')
            );
        }
    }

    public function enableCloudflare(): void
    {
        $this->switchTo(CacheConfig::CLOUDFLARE);
    }

    public function useVarnish(): void
    {
        if ((int) $this->config->getValue(PageCacheConfig::XML_PAGECACHE_TYPE) === CacheConfig::CLOUDFLARE) {
            $this->switchTo(PageCacheConfig::VARNISH);
        }
    }

    private function switchTo(int $type): void
    {
        $this->assertWritable();
        $this->writer->saveConfig([
            ConfigFilePool::APP_ENV => [
                'system' => ['default' => ['system' => ['full_page_cache' => ['caching_application' => $type]]]],
            ],
        ], lock: true);
        $this->configHash->regenerate(System::CONFIG_TYPE);
        $this->config->reinit();
        if ((int) $this->config->getValue(PageCacheConfig::XML_PAGECACHE_TYPE) !== $type) {
            throw new LocalizedException(__('Magento could not apply the new cache application.'));
        }
    }
}
