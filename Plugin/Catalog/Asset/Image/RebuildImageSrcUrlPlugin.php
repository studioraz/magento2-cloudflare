<?php
/*
 * Copyright © 2022 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Catalog\Asset\Image;

use Magento\Catalog\Model\View\Asset\Image as CatalogImageAsset;
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
     * @see \Magento\Catalog\Model\View\Asset\Image::getUrl
     *
     * @param CatalogImageAsset $subject
     * @param string $result
     * @return string
     */
    public function afterGetUrl(CatalogImageAsset $subject, string $result): string
    {
        if ($this->moduleState->isActive()) {
            $result = $this->ulrFormatter->getFormattedUrl($result);
        }

        return $result;
    }
}
