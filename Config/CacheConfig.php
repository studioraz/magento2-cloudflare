<?php

declare(strict_types=1);

namespace SR\Cloudflare\Config;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\PageCache\Model\Cache\Type;
use Magento\PageCache\Model\Config as PageCacheConfig;
use Magento\Store\Model\StoreManagerInterface;

class CacheConfig extends \SR\Gateway\Model\Config\Config
{
    public const EXT_ALIAS = 'srcloudflare';
    public const DEFAULT_PATH_GROUP = 'cache';
    public const WORKER_PATH_GROUP = 'worker';

    /**
     * Cloudflare caching application type for system/full_page_cache/caching_application
     */
    public const CLOUDFLARE = 3;

    private const KEY_ZONE_ID = 'zone_id';
    private const KEY_API_TOKEN = 'api_token';
    private const KEY_API_URL = 'api_url';

    private ?string $siteTag = null;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly StateInterface $cacheState,
        string $pathPattern = self::EXT_ALIAS . '/%s/%s'
    ) {
        parent::__construct($scopeConfig, $pathPattern);
    }

    /**
     * Check if Cloudflare is selected as the global caching application
     */
    public function isCloudflareApplication(): bool
    {
        return (int) $this->scopeConfig->getValue(PageCacheConfig::XML_PAGECACHE_TYPE) === self::CLOUDFLARE;
    }

    /**
     * Check if full_page_cache type is enabled in Cache Management
     */
    public function isPageCacheEnabled(): bool
    {
        return $this->cacheState->isEnabled(Type::TYPE_IDENTIFIER);
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
        return $this->isCloudflareApplication()
            && $this->isActive()
            && !empty($this->getZoneId())
            && !empty($this->getApiToken());
    }

    public function getResolvedApiUrl(): string
    {
        return sprintf((string) $this->getApiUrl(), (string) $this->getZoneId());
    }

    // ─── Worker configuration getters (srcloudflare/worker/*) ───

    public function getWorkerDebug(): bool
    {
        return (bool) $this->getValue(self::KEY_CONFIG_DEBUG, self::WORKER_PATH_GROUP);
    }

    public function getWorkerTtl(): int
    {
        $override = $this->getValue('default_ttl', self::WORKER_PATH_GROUP);

        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        return (int) ($this->scopeConfig->getValue(PageCacheConfig::XML_PAGECACHE_TTL) ?: 86400);
    }

    public function getWorkerHfpTtl(): int
    {
        return (int) ($this->getValue('hfp_ttl', self::WORKER_PATH_GROUP) ?: 120);
    }

    public function getWorkerAdminPath(): string
    {
        return (string) ($this->getValue('admin_path', self::WORKER_PATH_GROUP) ?: 'admin');
    }

    public function getWorkerBypassPaths(): string
    {
        return (string) ($this->getValue('bypass_paths', self::WORKER_PATH_GROUP) ?: '');
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
