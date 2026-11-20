<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model;

use Magento\Framework\FlagManager;
use SR\Cloudflare\Config\CacheConfig;

class ZoneDirectory
{
    public function __construct(
        private readonly CacheConfig $config,
        private readonly CloudflareApi $api,
        private readonly FlagManager $flags
    ) {
    }

    /** @return array<string, string> */
    public function refresh(): array
    {
        $zones = $this->api->getZones();
        $this->flags->saveFlag($this->getKey(), $zones);
        return $zones;
    }

    /** @return array<string, string>|null */
    public function getSavedZones(): ?array
    {
        $zones = $this->flags->getFlagData($this->getKey());
        return is_array($zones) ? $zones : null;
    }

    /** @return list<string> */
    public function getPurgeZoneIds(): array
    {
        if ($this->config->getAccountId() === '') {
            $zoneId = (string) $this->config->getZoneId();
            return preg_match('/^[a-f0-9]{32}$/D', $zoneId) ? [$zoneId] : [];
        }

        $selection = $this->config->getSelectedZoneIds();
        $zones = $selection === [] ? [] : $this->getSelectedZones($this->getSavedZones() ?? $this->refresh());
        if ($selection !== null) {
            $zones += $this->getZonesWithManagedRoutes();
        }

        return array_keys($zones);
    }

    /**
     * @param array<string, string> $zones
     * @return array<string, string>
     */
    public function getSelectedZones(array $zones): array
    {
        $selection = $this->config->getSelectedZoneIds();
        return $selection === null ? $zones : array_intersect_key($zones, array_flip($selection));
    }

    public function shouldPurgeZone(string $zoneId): bool
    {
        if ($this->config->getAccountId() === '') {
            return true;
        }

        $selection = $this->config->getSelectedZoneIds();
        return $selection === null
            || in_array($zoneId, $selection, true)
            || isset($this->getZonesWithManagedRoutes()[$zoneId]);
    }

    public function getDeploymentKey(): string
    {
        return 'sr_cf_' . substr(hash('sha256', $this->config->getAccountId()), 0, 32);
    }

    private function getZonesWithManagedRoutes(): array
    {
        $deployment = $this->flags->getFlagData($this->getDeploymentKey());
        return array_filter($deployment['routes'] ?? []);
    }

    private function getKey(): string
    {
        $credentials = $this->config->getAccountId() . ':' . $this->config->getApiToken();
        return 'sr_cf_zones_' . substr(hash('sha256', $credentials), 0, 32);
    }
}
