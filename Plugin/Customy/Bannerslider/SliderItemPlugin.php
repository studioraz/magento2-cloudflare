<?php
/*
 * Copyright © 2026 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Customy\Bannerslider;

use Customy\Bannerslider\Block\SliderItem;
use SR\Cloudflare\Config\ModuleState;
use SR\Cloudflare\Helper\CloudflareUrlFormatHelper;

class SliderItemPlugin
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
     * Rebuild Desktop Banner Image URL
     */
    public function afterGetBannerImageUrl(SliderItem $subject, $result)
    {
        if ($this->moduleState->isActive() && $result) {
            return $this->urlFormatter->getFormattedUrl($result);
        }
        return $result;
    }

    /**
     * Rebuild Mobile Banner Image URL
     */
    public function afterGetBannerImageMobileUrl(SliderItem $subject, $result)
    {
        if ($this->moduleState->isActive() && $result) {
            return $this->urlFormatter->getFormattedUrl($result);
        }
        return $result;
    }
}