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
use SR\Cloudflare\Config\Config;

class CloudflareUrlFormatHelper extends AbstractHelper
{
    public const EXTRA_PARAM_KEY_QUERY_STRING ='query_string';
    public const EXTRA_PARAM_KEY_ORIG_IMG_URL = 'original_image_url';


    private string $endpointMainPart = '/cdn-cgi/image/';

    private array $queryStringParams = [
        'format' => 'auto',
        'metadata' => 'none',
        'quality' => '85',
        'width' => null,
        'height' => null,
    ];

    private Config $config;
    private UrlInterface $url;

    /**
     * @param Context $context
     * @param Config $config
     * @param UrlInterface $url
     */
    public function __construct(
        Context $context,
        Config $config,
        UrlInterface $url
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->url = $url;
    }

    /**
     * @param string $initUrl initial url
     * @param array $extras set of extra parameters to build resulted URL
     *
     * @return string
     */
    public function getFormattedUrl(string $initUrl, array $extras = []): string
    {
        // NOTE: sample: data:image/png;base64,iVBORw0KGgoAAAANS...K5CYII=
        if (mb_strpos($initUrl, 'data:image/') !== false) {
            // NOTE: skip urls, which can be base64-encoded image-content
            return $initUrl;
        }

        // NOTE: sample: /cdn-cgi/image/format=auto,metadata=none,quality=85/media/logo/stores/1/logo_350.png
        if (mb_strpos($initUrl, $this->endpointMainPart) !== false) {
            // NOTE: already formatted
            return $initUrl;
        }

        if (!empty($extras[self::EXTRA_PARAM_KEY_ORIG_IMG_URL] ?? null)) {
            $initUrl = $extras[self::EXTRA_PARAM_KEY_ORIG_IMG_URL];
        }

        // NOTE: remove BaseUrl part
        $url = str_replace($this->url->getBaseUrl(), '', $initUrl);
        $url = '/' . trim($url, '/');

        $predefined = [];
        if (!empty($extras[self::EXTRA_PARAM_KEY_QUERY_STRING] ?? null)) {
            $predefined = $extras[self::EXTRA_PARAM_KEY_QUERY_STRING];
        }

        return $this->endpointMainPart . $this->getQueryStringParams($predefined) . $url;
    }

    /**
     * @return string
     */
    public function getQueryStringParams(array $predefined = []): string
    {
        $string = [];
        foreach ($this->queryStringParams as $code => $value) {
            if ($code === 'quality') {
                $value = $this->config->getImageQuality();
            }

            if ($value === null) {
                if (empty($predefined[$code] ?? null)) {
                    continue;
                }
                $value = $predefined[$code];
            }

            $string[] = "{$code}={$value}";
        }
        return implode(',', $string);
    }
}
