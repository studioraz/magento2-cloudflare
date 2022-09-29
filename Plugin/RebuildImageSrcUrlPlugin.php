<?php
/*
 * Copyright © 2022 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Plugin;

use Magento\Cms\Model\Template\Filter as CmsTemplateFilter;
use Magento\Theme\Block\Html\Header\Logo;
use Magento\Widget\Block\BlockInterface as WidgetBlockInterface;
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
     * @see \Magento\Theme\Block\Html\Header\Logo::getLogoSrc
     *
     * @param Logo $subject
     * @param string $result
     * @return string
     */
    public function afterGetLogoSrc(Logo $subject, string $result): string
    {
        if ($this->moduleState->isActive()) {
            $result = $this->ulrFormatter->getFormattedUrl($result);
        }

        return $result;
    }

    /**
     * AFTER Plugin
     * @see \Magento\Cms\Model\Template\Filter::mediaDirective
     *
     * @param CmsTemplateFilter $subject
     * @param string $result
     * @param ...$arguments
     * @return string
     */
    public function afterMediaDirective(CmsTemplateFilter $subject, string $result, ...$arguments): string
    {
        if ($this->moduleState->isActive()) {
            $result = $this->ulrFormatter->getFormattedUrl($result);
        }

        return $result;
    }

    /**
     * AFTER Plugin
     * @see \SR\Widgets\Block\Widget\Banners::getImagesPath::getMedia
     *
     * @param WidgetBlockInterface $subject
     * @param string $result
     * @param ...$arguments
     * @return string
     */
    public function afterGetMedia(WidgetBlockInterface $subject, string $result, ...$arguments): string
    {
        if ($this->moduleState->isActive()) {
            $result = $this->ulrFormatter->getFormattedUrl($result);
        }

        return $result;
    }
}
