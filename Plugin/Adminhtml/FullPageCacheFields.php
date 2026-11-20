<?php

declare(strict_types=1);

namespace SR\Cloudflare\Plugin\Adminhtml;

use Magento\Config\Controller\Adminhtml\System\Config\Save;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure\ElementVisibilityInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use SR\Cloudflare\Config\CacheConfig;

class FullPageCacheFields
{
    private const SYSTEM_RESOURCE = 'Magento_Config::config_system';
    private const GROUP_ID = 'magento_cache';
    private const ALIAS_PREFIX = CacheConfig::EXT_ALIAS . '/cache/' . self::GROUP_ID . '/';
    private const FIELD_PATHS = [
        'caching_application' => PageCacheConfig::XML_PAGECACHE_TYPE,
        'ttl' => PageCacheConfig::XML_PAGECACHE_TTL,
    ];

    public function __construct(
        private readonly AuthorizationInterface $authorization,
        private readonly SettingChecker $settingChecker
    ) {
    }

    public function afterIsDisabled(ElementVisibilityInterface $subject, bool $disabled, string $path): bool
    {
        if ($disabled || !str_starts_with($path, self::ALIAS_PREFIX)) {
            return $disabled;
        }

        $field = substr($path, strlen(self::ALIAS_PREFIX));
        return isset(self::FIELD_PATHS[$field]) && !$this->authorization->isAllowed(self::SYSTEM_RESOURCE);
    }

    public function afterGetConfigData(Save $subject, array $config): array
    {
        if ($config['section'] !== CacheConfig::EXT_ALIAS
            || empty($config['groups']['cache']['groups'][self::GROUP_ID]['fields'])
        ) {
            return $config;
        }

        $fields = &$config['groups']['cache']['groups'][self::GROUP_ID]['fields'];
        $canEdit = empty($config['website']) && empty($config['store'])
            && $this->authorization->isAllowed(self::SYSTEM_RESOURCE);
        foreach (self::FIELD_PATHS as $field => $path) {
            if (isset($fields[$field])
                && (!$canEdit || $this->settingChecker->isReadOnly($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT))
            ) {
                unset($fields[$field]);
            }
        }

        return $config;
    }
}
