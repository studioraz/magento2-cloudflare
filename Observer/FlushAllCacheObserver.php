<?php

declare(strict_types=1);

namespace SR\Cloudflare\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SR\Cloudflare\Model\CloudflareClient;
use SR\Cloudflare\Config\CacheConfig;

class FlushAllCacheObserver implements ObserverInterface
{
    public function __construct(
        private readonly CloudflareClient $cloudflareClient,
        private readonly CacheConfig $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $this->cloudflareClient->purgeAll();
    }
}
