<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Worker;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

class AdminLocation
{
    private const DEPLOY_ACTION = 'srcloudflare/worker/deploy';

    public function __construct(
        private readonly BackendUrl $backendUrl,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $stores
    ) {
    }

    /** @return array{string, string, bool} */
    public function resolve(): array
    {
        $url = $this->backendUrl->getUrl(self::DEPLOY_ACTION, ['_nosecret' => true]);
        $host = parse_url($url, PHP_URL_HOST);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $suffix = '/' . self::DEPLOY_ACTION;
        if (!is_string($host) || $host === '' || !str_ends_with($path, $suffix)) {
            throw new LocalizedException(__('Magento could not determine the Admin URL.'));
        }

        $adminPath = substr($path, 0, -strlen($suffix));
        if ($adminPath === '' || preg_match('/[?*#]/', $adminPath)) {
            throw new LocalizedException(__('Magento could not determine a valid Admin path.'));
        }

        $host = strtolower($host);
        return [$host, $adminPath, $this->isDedicatedAdminHost($host)];
    }

    private function isDedicatedAdminHost(string $host): bool
    {
        if (!$this->scopeConfig->isSetFlag('admin/url/use_custom')) {
            return false;
        }
        foreach ($this->stores->getStores() as $store) {
            foreach ([false, true] as $secure) {
                if (strtolower((string) parse_url($store->getBaseUrl('web', $secure), PHP_URL_HOST)) === $host) {
                    return false;
                }
            }
        }
        return true;
    }
}
