<?php

declare(strict_types=1);

namespace SR\Cloudflare\Observer;

use Magento\Framework\App\Cache\Tag\Resolver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use SR\Cloudflare\Model\CloudflareClient;
use SR\Cloudflare\Config\CacheConfig;

class PurgeByTags implements ObserverInterface
{
    public function __construct(
        private readonly CloudflareClient $cloudflareClient,
        private readonly Resolver $tagResolver,
        private readonly CacheConfig $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $object = $observer->getEvent()->getObject();

        if (!is_object($object)) {
            return;
        }

        $tags = $this->tagResolver->getTags($object);

        if (!empty($tags)) {
            $this->cloudflareClient->purgeByTags(array_unique($tags));
        }
    }
}
