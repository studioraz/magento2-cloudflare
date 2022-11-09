<?php
/*
 * Copyright © 2022 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Mirasvit\Asset\BadgeImage;

use Mirasvit\CatalogLabel\Model\Label\Display as LabelDisplay;
use SR\Cloudflare\Config\ModuleState;
use SR\Cloudflare\Helper\CloudflareUrlFormatHelper;

class RebuildImageSrcUrlPlugin
{
    private ModuleState $moduleState;
    private CloudflareUrlFormatHelper $urlFormatter;

    /**
     * @param ModuleState $moduleState
     * @param CloudflareUrlFormatHelper $urlFormatter
     */
    public function __construct(
        ModuleState $moduleState,
        CloudflareUrlFormatHelper $urlFormatter
    ) {
        $this->moduleState = $moduleState;
        $this->urlFormatter = $urlFormatter;
    }

    /**
     * AFTER Plugin
     * @see \Mirasvit\CatalogLabel\Model\Label\Display::getImageUrl
     *
     * @param LabelDisplay $subject
     * @param $result
     * @param ...$arguments
     * @return mixed|string
     */
    public function afterGetImageUrl(LabelDisplay $subject, $result, ...$arguments)
    {
        if ($this->moduleState->isActive() && $result) {
            $result = $this->urlFormatter->getFormattedUrl($result);
        }

        return $result;
    }
}
