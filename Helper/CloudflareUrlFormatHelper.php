<?php
/*
 * Copyright © 2022 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\UrlInterface;

class CloudflareUrlFormatHelper extends AbstractHelper
{
    private UrlInterface $url;

    /**
     * @param Context $context
     * @param UrlInterface $url
     */
    public function __construct(
        Context $context,
        UrlInterface $url
    ) {
        parent::__construct($context);
        $this->url = $url;
    }

    /**
     * @param string $initUrl initial url
     * @return string
     */
    public function getFormattedUrl(string $initUrl): string
    {
        // NOTE: sample: data:image/png;base64,iVBORw0KGgoAAAANS...K5CYII=
        if (mb_strpos($initUrl, 'data:image/') !== false) {
            // NOTE: skip urls, which can be base64-encoded image-content
            return $initUrl;
        }

        // NOTE: sample: /cdn-cgi/image/format=auto,metadata=none,quality=85/media/logo/stores/1/logo_350.png
        if (mb_strpos($initUrl, '/cdn-cgi/image/') !== false) {
            // NOTE: already formatted
            return $initUrl;
        }

        // NOTE: remove BaseUrl part
        $url = str_replace($this->url->getBaseUrl(), '', $initUrl);
        $url = '/' . trim($url, '/');

        return '/cdn-cgi/image/format=auto,metadata=none,quality=85' . $url;
    }
}
