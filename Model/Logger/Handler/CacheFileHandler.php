<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Logger\Handler;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Debug as LoggerDebugHandler;
use SR\Gateway\Api\Config\ConfigInterface;
use SR\Gateway\Model\Config\Config;

/**
 * File handler that reads active/debug flags from the 'cache' config group
 * instead of the hardcoded 'general' used by the Gateway base FileHandler.
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
        if (!$this->config->getValue(Config::KEY_CONFIG_ACTIVE, self::CONFIG_GROUP)) {
            return false;
        }

        if (!parent::isHandling($record)) {
            return false;
        }

        return (bool) $this->config->getValue(Config::KEY_CONFIG_DEBUG, self::CONFIG_GROUP);
    }
}
