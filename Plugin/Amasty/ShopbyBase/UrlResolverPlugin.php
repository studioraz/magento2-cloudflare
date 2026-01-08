<?php
/*
 * Copyright © 2026 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Amasty\ShopbyBase;

use Amasty\ShopbyBase\Model\OptionSettings\UrlResolver;
use SR\Cloudflare\Config\ModuleState;
use SR\Cloudflare\Helper\CloudflareUrlFormatHelper;

class UrlResolverPlugin
{
    private ModuleState $moduleState;
    private CloudflareUrlFormatHelper $urlFormatter;

    public function __construct(
        ModuleState $moduleState,
        CloudflareUrlFormatHelper $urlFormatter
    ) {
        $this->moduleState = $moduleState;
        $this->urlFormatter = $urlFormatter;
    }

    /**
     * Rebuild URL for main Brand Image
     */
    public function afterResolveImageUrl(UrlResolver $subject, $result)
    {
        if ($this->moduleState->isActive() && $result) {
            return $this->urlFormatter->getFormattedUrl($result);
        }
        return $result;
    }

    /**
     * Rebuild URL for Brand Slider Image
     */
    public function afterResolveSliderImageUrl(UrlResolver $subject, $result)
    {
        if ($this->moduleState->isActive() && $result) {
            return $this->urlFormatter->getFormattedUrl($result);
        }
        return $result;
    }
}