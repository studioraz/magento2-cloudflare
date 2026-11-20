<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Logger\Handler;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Debug as LoggerDebugHandler;
use SR\Gateway\Api\Config\ConfigInterface;
use SR\Gateway\Model\Config\Config;

/**
 * File handler that uses the cache group's Debug setting, including during connection setup.
 */
class CacheFileHandler extends LoggerDebugHandler
{
    private const CONFIG_GROUP = 'cache';

    protected ConfigInterface $config;

    public function __construct(
        DriverInterface $filesystem,
        ConfigInterface $config,
        ?string $filePath = null,
        ?string $fileName = null
    ) {
        $this->config = $config;

        parent::__construct($filesystem, $filePath, $fileName);
    }

    /**
     * @inheritDoc
     */
    public function isHandling($record): bool
    {
        if (!parent::isHandling($record)) {
            return false;
        }

        return (bool) $this->config->getValue(Config::KEY_CONFIG_DEBUG, self::CONFIG_GROUP);
    }
}
