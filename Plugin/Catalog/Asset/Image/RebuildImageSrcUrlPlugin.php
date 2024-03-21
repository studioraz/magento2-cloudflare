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
    private CloudflareUrlFormatHelper $urlFormatter;
    private CatalogImageAssetContextFactory $catalogImageAssetContextFactory;
    private CatalogImageHelperFactory $catalogImageHelperFactory;

    /**
     * @param ModuleState $moduleState
     * @param CloudflareUrlFormatHelper $urlFormatter
     * @param CatalogImageAssetContextFactory $catalogImageAssetContextFactory
     * @param CatalogImageHelperFactory $catalogImageHelperFactory
     */
    public function __construct(
        ModuleState $moduleState,
        CloudflareUrlFormatHelper $urlFormatter,
        CatalogImageAssetContextFactory $catalogImageAssetContextFactory,
        CatalogImageHelperFactory $catalogImageHelperFactory
    ) {
        $this->moduleState = $moduleState;
        $this->urlFormatter = $urlFormatter;
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
            // NOTE: Module State activity validation was encapsulated into getFormattedUrl method
            // TODO: update this logic in the future
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

        return $this->urlFormatter->getFormattedUrl($result, $extraParams);
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
            // NOTE: Module State activity validation was encapsulated into getFormattedUrl method
            // TODO: update this logic in the future
            $result = $this->urlFormatter->getFormattedUrl($result);
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
            return $context->getBaseUrl() . $this->appendSlashIfNotExists($asset->getFilePath());
        }
    }

    /**
     * Append / to an image file if start w/o it. by default Magento has it in DB
     *
     * @param $file
     * @return mixed|string
     */
    private function appendSlashIfNotExists($file)
    {
        return (substr($file, 0, 1) === "/") ? $file : "/" . $file;
    }
}
