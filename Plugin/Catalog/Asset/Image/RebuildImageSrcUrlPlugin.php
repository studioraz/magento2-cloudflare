<?php
/*
 * Copyright © 2022 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Catalog\Asset\Image;

use Magento\Catalog\Helper\ImageFactory as CatalogImageHelperFactory;
use Magento\Catalog\Model\Product\Media\Config as ProductMediaConfig;
use Magento\Catalog\Model\View\Asset\Image as CatalogImageAsset;
use Magento\Catalog\Model\View\Asset\Image\ContextFactory as CatalogImageAssetContextFactory;
use SR\Cloudflare\Config\ModuleState;
use SR\Cloudflare\Helper\CloudflareUrlFormatHelper;

class RebuildImageSrcUrlPlugin
{
    private ModuleState $moduleState;
    private CloudflareUrlFormatHelper $ulrFormatter;
    private CatalogImageAssetContextFactory $catalogImageAssetContextFactory;
    private CatalogImageHelperFactory $catalogImageHelperFactory;

    /**
     * @param ModuleState $moduleState
     * @param CloudflareUrlFormatHelper $ulrFormatter
     * @param CatalogImageAssetContextFactory $catalogImageAssetContextFactory
     * @param CatalogImageHelperFactory $catalogImageHelperFactory
     */
    public function __construct(
        ModuleState $moduleState,
        CloudflareUrlFormatHelper $ulrFormatter,
        CatalogImageAssetContextFactory $catalogImageAssetContextFactory,
        CatalogImageHelperFactory $catalogImageHelperFactory
    ) {
        $this->moduleState = $moduleState;
        $this->ulrFormatter = $ulrFormatter;
        $this->catalogImageAssetContextFactory = $catalogImageAssetContextFactory;
        $this->catalogImageHelperFactory = $catalogImageHelperFactory;
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
        if (!$this->moduleState->isActive()) {
            return $result;
        }

        $imageParam = $subject->getImageTransformationParameters();

        $extraParams = [
            CloudflareUrlFormatHelper::EXTRA_PARAM_KEY_QUERY_STRING => [
                'width' => $imageParam['width'] ?? null,
                'height' => $imageParam['height'] ?? null,
            ],
            CloudflareUrlFormatHelper::EXTRA_PARAM_KEY_ORIG_IMG_URL => $this->getOriginalImageUrl($subject) ?: null,
        ];

        return $this->ulrFormatter->getFormattedUrl($result, $extraParams);
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



    /**
     * NOTE:
     * @see \Magento\Catalog\Model\View\Asset\Image::getOriginalImageUrl
     *
     * Get URL to the original version of the product image.
     *
     * @return string
     */
    private function getOriginalImageUrl(CatalogImageAsset $asset)
    {
        $originalImageFile = $asset->getSourceFile();
        if (!$originalImageFile) {
            $helper = $this->catalogImageHelperFactory->create();
            return $helper->getDefaultPlaceholderUrl();
        } else {
            $context = $this->catalogImageAssetContextFactory->create();
            return $context->getBaseUrl() . $asset->getFilePath();
        }
    }
}
