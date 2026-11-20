<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Worker;

use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Model\PurgeQueue\QueueRepository;
use SR\Cloudflare\Model\ZoneDirectory;

class ForgetZone
{
    public function __construct(
        private readonly CacheConfig $config,
        private readonly ZoneDirectory $directory,
        private readonly Credentials $credentials,
        private readonly FlagManager $flags,
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $systemConfig,
        private readonly SettingChecker $settingChecker,
        private readonly QueueRepository $queue,
        private readonly ResourceConnection $resource,
        private readonly LockManagerInterface $lock
    ) {
    }

    public function execute(string $accountId, string $zoneId): array
    {
        if ($accountId === '' || $accountId !== $this->config->getAccountId()) {
            throw new LocalizedException(__('The Cloudflare account changed. Reload the page and try again.'));
        }
        if (!preg_match('/^[a-f0-9]{32}$/D', $zoneId)) {
            throw new LocalizedException(__('Enter a valid Cloudflare Zone ID.'));
        }
        if (!$this->lock->lock(CacheEngine::OPERATION_LOCK, 0)) {
            throw new LocalizedException(__('A Cloudflare Worker operation is already running.'));
        }

        try {
            $availableZones = $this->directory->refresh();
            if (!in_array($zoneId, $this->directory->getUnavailableZoneIds($availableZones), true)) {
                throw new LocalizedException(__(
                    'Zone %1 is available in Cloudflare or is no longer tracked by Magento.',
                    $zoneId
                ));
            }
            $selection = $this->config->getSelectedZoneIds();
            $updateSelection = $selection !== null && in_array($zoneId, $selection, true);
            if ($updateSelection && $this->settingChecker->isReadOnly(
                CacheConfig::ZONE_SELECTION_PATH,
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            )) {
                throw new LocalizedException(__('Remove Zone ID %1 from the locked zone selection and try again.', $zoneId));
            }
            $remaining = array_diff($selection ?? [], [$zoneId]);
            $savedSelection = $selection === null ? 'all' : (implode(',', $remaining) ?: 'none');
            $this->queue->withWriteLock(
                fn() => $this->forgetLocally($zoneId, $updateSelection ? $savedSelection : null)
            );

            return [
                'zones' => $availableZones,
                'unavailableZoneIds' => $this->directory->getUnavailableZoneIds($availableZones),
                'selection' => $savedSelection,
                'message' => (string) __(
                    'Zone %1 and its purge jobs were removed from Magento. Run Test Credentials before deploying.',
                    $zoneId
                ),
            ];
        } finally {
            $this->lock->unlock(CacheEngine::OPERATION_LOCK);
        }
    }

    private function forgetLocally(string $zoneId, ?string $selection): void
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $key = $this->directory->getDeploymentKey();
            $state = $this->flags->getFlagData($key) ?? [];
            if (isset($state['routes'][$zoneId]) || isset($state['unmanaged_zones'][$zoneId])) {
                unset($state['routes'][$zoneId], $state['unmanaged_zones'][$zoneId]);
                $this->flags->saveFlag($key, $state);
            }
            if ($selection !== null) {
                $this->configWriter->save(CacheConfig::ZONE_SELECTION_PATH, $selection);
            }
            $this->queue->deleteForZone($zoneId);
            $this->credentials->invalidate();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        if ($selection !== null) {
            $this->systemConfig->reinit();
        }
    }
}
