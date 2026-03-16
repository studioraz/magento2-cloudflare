<?php

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Layout;

use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Layout;
use Magento\PageCache\Model\Config as PageCacheConfig;
use Magento\PageCache\Model\Spi\PageCacheTagsPreprocessorInterface;
use SR\Cloudflare\Config\CacheConfig;

/**
 * Set cache headers and collect cache tags for Cloudflare FPC.
 *
 * Mirrors \Magento\PageCache\Model\Layout\LayoutPlugin behaviour:
 * - afterGenerateElements: sets public Cache-Control headers
 * - afterGetOutput: collects X-Magento-Tags from all blocks
 *
 * Unlike the Varnish path, ESI blocks are NOT skipped because
 * Cloudflare Workers do not support ESI — all block tags must be
 * included in the parent response.
 */
class LayoutPlugin
{
    public function __construct(
        private readonly ResponseInterface $response,
        private readonly PageCacheConfig $pageCacheConfig,
        private readonly CacheConfig $config,
        private readonly MaintenanceMode $maintenanceMode,
        private readonly PageCacheTagsPreprocessorInterface $pageCacheTagsPreprocessor
    ) {
    }

    public function afterGenerateElements(Layout $subject): void
    {
        if ($subject->isCacheable()
            && !$this->maintenanceMode->isOn()
            && $this->config->isCloudflareApplication()
            && $this->config->isPageCacheEnabled()
        ) {
            $this->response->setPublicHeaders($this->pageCacheConfig->getTtl());
        }
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function afterGetOutput(Layout $subject, $result)
    {
        if ($subject->isCacheable()
            && $this->config->isCloudflareApplication()
            && $this->config->isPageCacheEnabled()
        ) {
            $tags = [];

            foreach ($subject->getAllBlocks() as $block) {
                if ($block instanceof IdentityInterface) {
                    $tags[] = $block->getIdentities();
                }
            }

            $tags = array_unique(array_merge([], ...$tags));
            $tags = $this->pageCacheTagsPreprocessor->process($tags);
            $this->response->setHeader('X-Magento-Tags', implode(',', $tags));
        }

        return $result;
    }
}