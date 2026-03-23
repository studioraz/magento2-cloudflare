<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\System\Config\Source;

use Magento\PageCache\Model\System\Config\Source\Application;
use SR\Cloudflare\Config\CacheConfig;

class ApplicationPlugin
{
    public function afterToOptionArray(Application $subject, array $result): array
    {
        $result[] = [
            'value' => CacheConfig::CLOUDFLARE,
            'label' => __('Cloudflare CDN'),
        ];

        return $result;
    }

    public function afterToArray(Application $subject, array $result): array
    {
        $result[CacheConfig::CLOUDFLARE] = __('Cloudflare CDN');

        return $result;
    }
}