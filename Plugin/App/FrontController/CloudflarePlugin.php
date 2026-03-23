<?php

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\App\FrontController;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\PageCache\Version;
use Magento\Framework\App\Response\Http as ResponseHttp;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Controller\ResultInterface;
use SR\Cloudflare\Config\CacheConfig;

class CloudflarePlugin
{
    public function __construct(
        private readonly CacheConfig $config,
        private readonly Version $version,
        private readonly AppState $state
    ) {
    }

    /**
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterDispatch(
        FrontControllerInterface $subject,
        ResponseInterface|ResultInterface $result
    ): ResponseHttp|ResultInterface|ResponseInterface {
        if ($this->config->isCloudflareApplication()
            && $this->config->isPageCacheEnabled()
            && $result instanceof ResponseHttp
        ) {
            $this->version->process();

            if ($this->state->getMode() === AppState::MODE_DEVELOPER) {
                $result->setHeader('X-Magento-Debug', '1');
            }
        }

        return $result;
    }
}
