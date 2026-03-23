<?php

declare(strict_types=1);

namespace SR\Cloudflare\Plugin;

use Magento\Framework\App\Response\Http;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use SR\Cloudflare\Config\CacheConfig;

class AddCacheTagHeader
{
    public function __construct(
        private readonly CacheConfig $config
    ) {
    }

    /**
     * Copy X-Magento-Tags into Cache-Tag header with a site-wide tag prepended.
     *
     * Runs before Magento's BuiltinPlugin/VarnishPlugin (sortOrder=-10 vs their default 0)
     * so the header is captured before Kernel::process() clears X-Magento-Tags.
     *
     * The site-wide tag (hostname) enables full-cache flush by tag
     * without purge_everything (which would also wipe Image Transformations cache).
     */
    public function afterRenderResult(
        ResultInterface $subject,
        ResultInterface $result,
        ResponseInterface $response
    ): ResultInterface {
        if (!$response instanceof Http) {
            return $result;
        }

        if (!$this->config->isCloudflareApplication()) {
            return $result;
        }

        $magentoTags = $response->getHeader('X-Magento-Tags');

        if (!$magentoTags) {
            return $result;
        }

        $value = is_object($magentoTags) ? $magentoTags->getFieldValue() : (string) $magentoTags;

        if ($value === '') {
            return $result;
        }

        $tags = explode(',', $value);
        array_unshift($tags, $this->config->getSiteTag());
        $response->setHeader('Cache-Tag', implode(',', $tags));

        return $result;
    }
}
