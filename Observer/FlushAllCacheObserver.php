<?php

declare(strict_types=1);

namespace SR\Cloudflare\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Model\CloudflareClient;
use SR\Cloudflare\Model\PurgeQueue\QueueRepository;

class FlushAllCacheObserver implements ObserverInterface
{
    public function __construct(
        private readonly CloudflareClient $cloudflareClient,
        private readonly CacheConfig $config,
        private readonly QueueRepository $queueRepository
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $siteTags = $this->config->getAllSiteTags();

        if ($this->config->isAsyncPurgeEnabled()) {
            if ($this->queueRepository->enqueueTags($siteTags)) {
                return;
            }
        }

        try {
            $this->cloudflareClient->purgeByTags($siteTags);
        } catch (\Exception) {
            return;
        }
    }
}
