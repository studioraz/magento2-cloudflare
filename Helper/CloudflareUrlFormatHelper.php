<?php
/*
 * Copyright © 2022 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use SR\Cloudflare\Config\Config;
use SR\Cloudflare\Config\ModuleState;
use SR\Cloudflare\Model\System\Config\Source\ImageFit;

class CloudflareUrlFormatHelper extends AbstractHelper
{
    public const EXTRA_PARAM_KEY_QUERY_STRING ='query_string';
    public const EXTRA_PARAM_KEY_ORIG_IMG_URL = 'original_image_url';


    private string $endpointMainPart = '/cdn-cgi/image/';

    private array $queryStringParams = [
        'format' => 'auto',
        'metadata' => 'none',
        'quality' => '85',
        'fit' => ImageFit::NONE,
        'width' => null,
        'height' => null,
    ];

    private ModuleState $moduleState;
    private Config $config;

    /**
     * @param Context $context
     * @param ModuleState $moduleState
     * @param Config $config
     */
    public function __construct(
        Context $context,
        ModuleState $moduleState,
        Config $config
    ) {
        parent::__construct($context);
        $this->moduleState = $moduleState;
        $this->config = $config;
    }

    /**
     * @param string $initUrl initial url
     * @param array $extras set of extra parameters to build resulted URL
     *
     * @return string
     */
    public function getFormattedUrl(string $initUrl, array $extras = []): string
    {
        if (!$this->moduleState->isActive()) {
            return $initUrl;
        }

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
        $baseUrl = $this->_urlBuilder->getBaseUrl();
        $url = str_replace($baseUrl, '', $initUrl);
        $url = '/' . trim($url, '/');

        $baseUrl = rtrim($baseUrl, '/');

        $predefined = [];
        if (!empty($extras[self::EXTRA_PARAM_KEY_QUERY_STRING] ?? null)) {
            $predefined = $extras[self::EXTRA_PARAM_KEY_QUERY_STRING];
        }

        return $baseUrl . $this->endpointMainPart . $this->getQueryStringParams($predefined) . $url;
    }

    /**
     * @return string
     */
    public function getQueryStringParams(array $predefined = []): string
    {
        $string = [];
        foreach ($this->queryStringParams as $code => $value) {
            // NOTE: step to fetch values of Specific params
            if ($code === 'quality') {
                $value = $this->config->getImageQuality();
            } elseif ($code === 'fit') {
                $value = $this->config->getImageFit();
                if ($value === ImageFit::NONE) {
                    continue;// NOTE: there is no need to add such param with 'none' value.
                }
            }

            // NOTE: on $value=NULL validate predefined values
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
