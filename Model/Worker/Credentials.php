<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Worker;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\ZoneDirectory;

class Credentials
{
    public function __construct(
        private readonly CacheConfig $config,
        private readonly CloudflareApi $api,
        private readonly ZoneDirectory $directory,
        private readonly FlagManager $flags
    ) {
    }

    /** @return array<string, string> */
    public function verify(): array
    {
        $this->invalidate();
        $this->api->verifyToken();
        $available = $this->directory->refresh();
        $this->directory->assertSelectedZonesAvailable($available);
        $zones = $this->directory->getSelectedZones($available);
        $this->checkPurge($zones);

        if (!$this->flags->saveFlag($this->flagCode(), [
            'token_hash' => $this->tokenHash(),
            'zones' => array_keys($zones),
        ])) {
            throw new LocalizedException(__('Could not save the Cloudflare credentials verification.'));
        }

        return $available;
    }

    public function canDeploy(array $availableZones): bool
    {
        $zones = $this->directory->getSelectedZones($availableZones);
        $verified = $this->flags->getFlagData($this->flagCode());
        return $zones !== [] && ($verified['token_hash'] ?? null) === $this->tokenHash()
            && $this->directory->getUnavailableZoneIds($availableZones) === []
            && array_diff(array_keys($zones), $verified['zones'] ?? []) === [];
    }

    public function invalidate(): void
    {
        $this->flags->deleteFlag($this->flagCode());
    }

    public function checkPurge(array $zones): void
    {
        $tag = 'sr-cloudflare-test-' . bin2hex(random_bytes(16));
        foreach ($zones as $id => $domain) {
            try {
                $this->api->request('POST', '/zones/' . $id . '/purge_cache', ['tags' => [$tag]]);
            } catch (CloudflareApiException $exception) {
                throw new LocalizedException(
                    __('Could not verify Cache Purge in %1. %2', $domain, $exception->getMessage()),
                    $exception
                );
            }
        }
    }

    private function flagCode(): string
    {
        return $this->directory->getDeploymentKey() . '_verified';
    }

    private function tokenHash(): string
    {
        return hash('sha256', (string) $this->config->getApiToken());
    }
}
