<?php

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Controller\Result;

use Magento\Framework\App\PageCache\Version;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Registry;
use SR\Cloudflare\Config\CacheConfig;

class CloudflarePlugin
{
    public function __construct(
        private readonly CacheConfig $config,
        private readonly Version $version,
        private readonly AppState $state,
        private readonly Registry $registry
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterRenderResult(
        ResultInterface $subject,
        ResultInterface $result,
        ResponseHttp $response
    ): ResultInterface {
        $usePlugin = $this->registry->registry('use_page_cache_plugin');

        if ($this->config->isCloudflareApplication()
            && $this->config->isPageCacheEnabled()
            && $usePlugin
        ) {
            $this->version->process();

            if ($this->state->getMode() === AppState::MODE_DEVELOPER) {
                $response->setHeader('X-Magento-Debug', '1');
            }
        }

        return $result;
    }
}