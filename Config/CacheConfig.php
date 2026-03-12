<?php

declare(strict_types=1);

namespace SR\Cloudflare\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;

class CacheConfig extends \SR\Gateway\Model\Config\Config
{
    public const EXT_ALIAS = 'srcloudflare';
    public const DEFAULT_PATH_GROUP = 'cache';

    private const KEY_ZONE_ID = 'zone_id';
    private const KEY_API_TOKEN = 'api_token';
    private const KEY_API_URL = 'api_url';

    private ?string $siteTag = null;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        string $pathPattern = self::EXT_ALIAS . '/%s/%s'
    ) {
        parent::__construct($scopeConfig, $pathPattern);
    }

    public function isActive(): bool
    {
        return (bool) $this->getValue(self::KEY_CONFIG_ACTIVE, self::DEFAULT_PATH_GROUP);
    }

    public function getZoneId(): ?string
    {
        return $this->getValue(self::KEY_ZONE_ID, self::DEFAULT_PATH_GROUP);
    }

    public function getApiToken(): ?string
    {
        return $this->getValue(self::KEY_API_TOKEN, self::DEFAULT_PATH_GROUP);
    }

    public function getApiUrl(): ?string
    {
        return $this->getValue(self::KEY_API_URL, self::DEFAULT_PATH_GROUP);
    }

    public function isDebugEnabled(): bool
    {
        return (bool) $this->getValue(self::KEY_CONFIG_DEBUG, self::DEFAULT_PATH_GROUP);
    }

    public function isConfigured(): bool
    {
        return $this->isActive()
            && !empty($this->getZoneId())
            && !empty($this->getApiToken());
    }

    public function getResolvedApiUrl(): string
    {
        return sprintf((string) $this->getApiUrl(), (string) $this->getZoneId());
    }

    /**
     * Get hostname-based site-wide tag used for full cache flush
     * and to scope tags per site when multiple sites share the same Cloudflare zone.
     *
     * e.g. "all4pet.mystore.today" → "all4pet_mystore_today"
     */
    public function getSiteTag(): string
    {
        if ($this->siteTag === null) {
            try {
                $baseUrl = $this->storeManager->getStore()->getBaseUrl();
                $host = (string) parse_url($baseUrl, PHP_URL_HOST);
                $this->siteTag = str_replace(['.', '-'], '_', $host);
            } catch (\Exception) {
                $this->siteTag = 'default';
            }
        }

        return $this->siteTag;
    }
}
