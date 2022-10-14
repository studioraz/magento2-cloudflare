<?php
/*
 * Copyright © 2022 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Catalog\Product\Media\Config;

use Magento\Catalog\Model\Product\Media\Config as ProductMediaConfig;
use SR\Cloudflare\Config\ModuleState;
use SR\Cloudflare\Helper\CloudflareUrlFormatHelper;

class RebuildImageSrcUrlPlugin
{
    private ModuleState $moduleState;
    private CloudflareUrlFormatHelper $ulrFormatter;

    /**
     * @param ModuleState $moduleState
     * @param CloudflareUrlFormatHelper $ulrFormatter
     */
    public function __construct(
        ModuleState $moduleState,
        CloudflareUrlFormatHelper $ulrFormatter
    ) {
        $this->moduleState = $moduleState;
        $this->ulrFormatter = $ulrFormatter;
    }

    /**
     * AFTER Plugin
     * @see \Magento\Catalog\Model\Product\Media\Config::getMediaUrl
     *
     * @param ProductMediaConfig $subject
     * @param string $result
     * @return string
     */
    public function afterGetMediaUrl(ProductMediaConfig $subject, string $result): string
    {
        if ($this->moduleState->isActive()) {
            $result = $this->ulrFormatter->getFormattedUrl($result);
        }

        return $result;
    }
}
