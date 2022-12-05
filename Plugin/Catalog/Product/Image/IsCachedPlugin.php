<?php
/*
 * Copyright © 2022 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Catalog\Product\Image;

use Magento\Catalog\Model\Product\Image as ProductImage;
use SR\Cloudflare\Config\ModuleState;

class IsCachedPlugin
{
    private ModuleState $moduleState;

    /**
     * @param ModuleState $moduleState
     */
    public function __construct(
        ModuleState $moduleState
    ) {
        $this->moduleState = $moduleState;
    }

    /**
     * AROUND Plugin
     * @see \Magento\Catalog\Model\Product\Image::isCached
     *
     * @param ProductImage $subject
     * @param callable $proceed
     * @return bool
     */
    public function aroundIsCached(ProductImage $subject, callable $proceed): bool
    {
        // NOTE: in order SR_Cloudflare module is active assume that catalog/product images are cached By Default
        if ($this->moduleState->isActive()) {
            return true;
        }

        return $proceed();
    }
}
