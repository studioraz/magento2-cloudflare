<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Worker;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\ZoneDirectory;

class Delete
{
    public function __construct(
        private readonly CloudflareApi $api,
        private readonly CacheConfig $config,
        private readonly ZoneDirectory $directory,
        private readonly Routes $routes,
        private readonly LockManagerInterface $lock,
        private readonly FlagManager $flags,
        private readonly CacheEngine $cacheEngine
    ) {
    }

    public function canDelete(): bool
    {
        $state = $this->flags->getFlagData($this->directory->getDeploymentKey());
        return $this->cacheEngine->isAllowed() && ($state['worker'] ?? null) === $this->config->getWorkerName();
    }

    public function execute(string $accountId, string $worker): array
    {
        $this->cacheEngine->assertAllowed();
        if ($accountId !== $this->config->getAccountId() || $worker !== $this->config->getWorkerName()) {
            throw new LocalizedException(__('The Cloudflare account or Worker changed. Reload the page and try again.'));
        }
        $workerPath = $this->api->workerPath();
        $key = $this->directory->getDeploymentKey();
        if (!$this->lock->lock(CacheEngine::OPERATION_LOCK, 0)) {
            throw new LocalizedException(__('A Cloudflare Worker operation is already running.'));
        }

        try {
            $state = $this->flags->getFlagData($key);
            if (($state['worker'] ?? null) !== $worker) {
                throw new LocalizedException(__('Magento can only delete a Worker it manages.'));
            }
            if ($this->config->isCloudflareApplication()) {
                $this->cacheEngine->assertWritable();
            }
            return $this->deleteWorker($workerPath, $state, $key);
        } finally {
            $this->lock->unlock(CacheEngine::OPERATION_LOCK);
        }
    }

    private function deleteWorker(string $workerPath, array $state, string $key): array
    {
        if (!($state['worker_deleted'] ?? false)) {
            // Stop the Worker before removing bypass routes that may protect live traffic.
            try {
                $this->api->request('DELETE', $workerPath);
            } catch (CloudflareApiException $exception) {
                if ($exception->getCode() !== 404 && !$this->isAbsentAfterUncertainDelete($workerPath, $exception)) {
                    throw $exception;
                }
            }
            $state['worker_deleted'] = true;
            $this->flags->saveFlag($key, $state);
        }

        try {
            $this->cacheEngine->useVarnish();
        } catch (LocalizedException $exception) {
            throw new LocalizedException(
                __('Worker deleted. Retry Delete Worker to finish switching Magento to Varnish. %1', $exception->getMessage()),
                $exception
            );
        }

        $errors = [];
        $zones = $this->directory->getSavedZones() ?? [];
        foreach (array_keys(array_filter($state['routes'] ?? [])) as $zone) {
            try {
                $this->routes->removeObsolete($zone, [], $this->routes->getExisting($zone), $state, $key);
                unset($state['routes'][$zone]);
                $this->flags->saveFlag($key, $state);
            } catch (CloudflareApiException $exception) {
                $errors[$zones[$zone] ?? $zone] = $exception->getMessage();
            }
        }

        if ($errors !== []) {
            return [
                'error' => (string) __('Worker deleted. Retry Delete Worker to finish removing its routes.'),
                'results' => $errors,
            ];
        }

        $this->flags->deleteFlag($key);
        return ['message' => (string) __('Worker and Magento-managed routes deleted.')];
    }

    private function isAbsentAfterUncertainDelete(string $workerPath, CloudflareApiException $exception): bool
    {
        $status = $exception->getCode();
        if ($status !== 0 && $status < 500) {
            return false;
        }

        try {
            $this->api->request('GET', $workerPath . '/settings');
        } catch (CloudflareApiException $verification) {
            return $verification->getCode() === 404;
        }

        return false;
    }
}
