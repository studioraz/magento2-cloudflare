<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Worker;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\ZoneDirectory;

class Deploy
{
    public function __construct(
        private readonly CloudflareApi $api,
        private readonly ZoneDirectory $directory,
        private readonly CacheConfig $config,
        private readonly AdminLocation $adminLocation,
        private readonly RoutePlanner $planner,
        private readonly Routes $routes,
        private readonly LockManagerInterface $lock,
        private readonly FlagManager $flags,
        private readonly Json $json,
        private readonly Credentials $credentials,
        private readonly CacheEngine $cacheEngine
    ) {
    }

    public function canDeploy(): bool
    {
        if (!$this->cacheEngine->isAllowed()) {
            return false;
        }
        $state = $this->flags->getFlagData($this->directory->getDeploymentKey());
        if (!empty($state['worker_deleted'])) {
            return false;
        }
        if ($this->config->isZoneSelectionEmpty()) {
            return ($state['worker'] ?? null) === $this->config->getWorkerName();
        }

        return $this->credentials->canDeploy($this->directory->getSavedZones() ?? []);
    }

    public function execute(): array
    {
        $this->cacheEngine->assertAllowed();
        $workerPath = $this->api->workerPath();
        $key = $this->directory->getDeploymentKey();
        if (!$this->lock->lock(CacheEngine::OPERATION_LOCK, 0)) {
            throw new LocalizedException(__('A Cloudflare Worker operation is already running.'));
        }

        try {
            return $this->deploy($workerPath, $key);
        } finally {
            $this->lock->unlock(CacheEngine::OPERATION_LOCK);
        }
    }

    private function deploy(string $workerPath, string $key): array
    {
        $state = $this->flags->getFlagData($key) ?: [];
        if ($state['worker_deleted'] ?? false) {
            throw new LocalizedException(__('Retry Delete Worker to finish removing routes before deploying again.'));
        }

        $availableZones = $this->directory->refresh();
        $this->directory->assertSelectedZonesAvailable($availableZones);
        $zones = $this->directory->getSelectedZones($availableZones);
        // An explicit "none" allows cleanup without deploying a new Worker version.
        if ($zones === [] && ($state === [] || !$this->config->isZoneSelectionEmpty())) {
            throw new LocalizedException(__('Select and save at least one available Cloudflare zone before deploying.'));
        }
        $wasActive = $this->config->isCloudflareApplication();
        if ($zones !== [] || $wasActive) {
            $this->cacheEngine->assertWritable();
        }
        if ($zones !== []) {
            if (!$this->credentials->canDeploy($availableZones)) {
                throw new LocalizedException(__('Run Test Credentials for the saved account, token and zones before deploying.'));
            }
            $this->api->verifyToken();
            $this->credentials->checkPurge($zones);
        }

        $worker = $this->config->getWorkerName();
        if ($state && $state['worker'] !== $worker) {
            throw new LocalizedException(__('This account is already managed with Worker "%1".', $state['worker']));
        }
        if ($state === [] && $this->workerExists($workerPath)) {
            throw new LocalizedException(__('Worker "%1" already exists. Choose a name for a new managed Worker.', $worker));
        }

        $plans = [];
        $existing = [];
        $adminRoutes = [];
        if ($zones !== []) {
            [$adminHost, $adminPath, $dedicated] = $this->adminLocation->resolve();
            $routeBypassPaths = $this->config->getWorkerRouteBypassPaths();
            foreach ($zones as $id => $domain) {
                $plans[$id] = $this->planner->plan(
                    $domain,
                    $worker,
                    $adminHost,
                    $adminPath,
                    $dedicated,
                    $routeBypassPaths
                );
                $existing[$id] = $this->routes->getExisting($id);
                $this->checkConflicts($domain, $plans[$id], $existing[$id], $state['routes'][$id] ?? []);
                $adminRoutes[$id] = $this->planner->getAdminBypassPattern($domain, $adminHost, $adminPath, $dedicated);
            }
        }

        $result = ['zones' => $availableZones, 'results' => []];
        if (!$this->removeDeselectedRoutes($availableZones, $state, $key, $result['results'])) {
            return $result + ['error' => (string) __('Retry Start Deploy after resolving the route removal errors.')];
        }
        if ($zones === []) {
            if (!empty($state['unmanaged_zones'])) {
                return $result + [
                    'error' => (string) __('Remove the remaining manual routes or use Delete Worker to switch to Varnish.'),
                ];
            }
            $this->cacheEngine->useVarnish();
            return $result + ['message' => (string) ($wasActive
                ? __('No zones selected. Magento switched to Varnish.')
                : __('No zones selected. Magento-managed route cleanup complete.'))];
        }

        // Record the attempt before uploading so an interrupted request can be retried.
        $state += ['worker' => $worker, 'routes' => []];
        $originalRoutes = $state['routes'];
        if (!$wasActive || empty($state['cache_namespace'])) {
            $state['cache_namespace'] = bin2hex(random_bytes(16));
        }
        $this->flags->saveFlag($key, $state);

        // Keep Admin traffic off the Worker when switching to a version that relies on routes.
        foreach (array_filter($adminRoutes) as $id => $pattern) {
            try {
                $existing[$id][$pattern] = $this->ensureRoute(
                    $id,
                    $pattern,
                    null,
                    $existing[$id][$pattern] ?? null,
                    $state,
                    $key
                );
            } catch (CloudflareApiException $exception) {
                return $result + [
                    'error' => (string) __(
                        'Could not configure the Magento Admin bypass in %1. %2',
                        $zones[$id],
                        $exception->getMessage()
                    ),
                ];
            }
        }
        $this->upload($workerPath, $state['cache_namespace']);
        if ($this->api->getActiveDeployment() === null) {
            throw new LocalizedException(__('Cloudflare has not confirmed an active Worker deployment. Retry Start Deploy.'));
        }

        $failed = false;
        foreach ($zones as $id => $domain) {
            try {
                $this->applyRoutes($id, $plans[$id], $existing[$id], $state, $key);
                unset($state['unmanaged_zones'][$id]);
                foreach ($existing[$id] as $route) {
                    if (($route['script'] ?? null) === $worker
                        && !$this->routes->isOwned($route, $originalRoutes[$id] ?? [])
                    ) {
                        $state['unmanaged_zones'][$id] = $domain;
                        break;
                    }
                }
                $this->flags->saveFlag($key, $state);
                $result['results'][$domain] = (string) __('Routes configured.');
            } catch (CloudflareApiException $exception) {
                $failed = true;
                $result['results'][$domain] = $exception->getMessage();
            }
        }

        if (!$failed) {
            try {
                $this->cacheEngine->enableCloudflare();
                return $result + ['message' => (string) __('Worker deployed. Magento is using Cloudflare CDN.')];
            } catch (LocalizedException $exception) {
                $result['error'] = $exception->getMessage();
            }
        }

        if (!$wasActive && !$this->config->isCloudflareApplication()) {
            foreach ($zones as $id => $domain) {
                try {
                    if ($this->restoreWorkerRoutes($id, $existing[$id], $originalRoutes[$id] ?? [], $state, $key)) {
                        $result['results'][$domain] .= ' ' . __('New Worker bindings rolled back.');
                    }
                } catch (CloudflareApiException $exception) {
                    $result['results'][$domain] .= ' ' . __('Could not roll back Worker bindings. %1', $exception->getMessage());
                }
            }
        }

        return $result + ['error' => (string) __('Retry deployment to finish configuring the failed zones.')];
    }

    private function restoreWorkerRoutes(
        string $zone,
        array $before,
        array $ownedBefore,
        array &$state,
        string $key
    ): bool
    {
        $changed = array_filter(
            $state['routes'][$zone] ?? [],
            static fn(array $route, string|int $id): bool => isset($route['script']) && $route !== ($ownedBefore[$id] ?? null),
            ARRAY_FILTER_USE_BOTH
        );
        if ($changed === []) {
            return false;
        }

        $existing = $this->routes->getExisting($zone);
        foreach ($changed as $id => $route) {
            $pattern = $route['pattern'];
            if (!isset($existing[$pattern]) || !$this->routes->isOwned($existing[$pattern], [$id => $route])) {
                continue;
            }
            if (isset($before[$pattern])) {
                $this->ensureRoute($zone, $pattern, $before[$pattern]['script'] ?? null, $existing[$pattern], $state, $key);
            } else {
                $this->routes->removeObsolete($zone, [], [$pattern => $existing[$pattern]], $state, $key);
            }
        }
        return true;
    }

    private function removeDeselectedRoutes(array $availableZones, array &$state, string $key, array &$results): bool
    {
        $selection = $this->config->getSelectedZoneIds();
        if ($selection === null) {
            return true;
        }

        $trackedZones = array_filter($state['routes'] ?? []) + ($state['unmanaged_zones'] ?? []);
        $deselectedRoutes = array_diff_key($trackedZones, array_flip($selection));
        $complete = true;
        foreach (array_keys($deselectedRoutes) as $id) {
            $domain = $availableZones[$id] ?? null;
            if ($domain === null) {
                $results[$id] = (string) __('Zone is unavailable to this token. Restore access to remove its routes.');
                $complete = false;
                continue;
            }

            try {
                $existing = $this->routes->getExisting($id);
                $owned = $state['routes'][$id] ?? [];
                $this->routes->removeObsolete($id, [], $existing, $state, $key);
                unset($state['routes'][$id]);
                unset($state['unmanaged_zones'][$id]);
                $results[$domain] = (string) __('Magento-managed routes removed.');
                foreach ($existing as $route) {
                    if (($route['script'] ?? null) === $state['worker'] && !$this->routes->isOwned($route, $owned)) {
                        $state['unmanaged_zones'][$id] = $domain;
                        $results[$domain] = (string) __(
                            'Magento-managed routes removed. An unmanaged route still invokes this Worker.'
                        );
                        break;
                    }
                }
                $this->flags->saveFlag($key, $state);
            } catch (CloudflareApiException $exception) {
                $results[$domain] = (string) __(
                    'Could not remove Magento-managed routes. %1',
                    $exception->getMessage()
                );
                $complete = false;
            }
        }

        return $complete;
    }

    private function upload(string $workerPath, string $cacheNamespace): void
    {
        $variables = [
            'DEBUG' => $this->config->getWorkerDebug() ? 'true' : 'false',
            'DEFAULT_TTL' => (string) $this->config->getWorkerTtl(),
            'HFP_TTL' => (string) $this->config->getWorkerHfpTtl(),
            'BYPASS_PATHS' => $this->config->getWorkerBypassPaths(),
            'MAGENTO_CACHE_NAMESPACE' => $cacheNamespace,
        ];
        $module = 'FPC-worker.js';
        $metadata = $this->json->serialize([
            'main_module' => $module,
            'compatibility_date' => '2024-11-11',
            'bindings' => array_map(
                static fn(string $name, string $text): array => ['type' => 'plain_text', 'name' => $name, 'text' => $text],
                array_keys($variables),
                array_values($variables)
            ),
        ]);
        $this->api->request('PUT', $workerPath, [
            'metadata' => new \CURLStringFile($metadata, 'metadata.json', 'application/json'),
            $module => new \CURLFile(dirname(__DIR__, 2) . '/CFWorker/' . $module, 'application/javascript+module', $module),
        ], true);
        $this->api->request('POST', $workerPath . '/subdomain', ['enabled' => false, 'previews_enabled' => false]);
    }

    private function workerExists(string $workerPath): bool
    {
        try {
            $this->api->request('GET', $workerPath . '/settings');
            return true;
        } catch (CloudflareApiException $exception) {
            if ($exception->getCode() === 404) {
                return false;
            }
            throw $exception;
        }
    }

    private function checkConflicts(string $domain, array $plan, array $existing, array $owned): void
    {
        foreach ($plan as $pattern => $script) {
            $route = $existing[$pattern] ?? null;
            if ($route === null || ($route['script'] ?? null) === $script || $this->routes->isOwned($route, $owned)) {
                continue;
            }
            throw new LocalizedException(__('Zone %1 already has a conflicting Worker route for %2.', $domain, $pattern));
        }
    }

    private function applyRoutes(string $zone, array $plan, array $existing, array &$state, string $key): void
    {
        foreach ($plan as $pattern => $script) {
            $this->ensureRoute($zone, $pattern, $script, $existing[$pattern] ?? null, $state, $key);
        }
        $this->routes->removeObsolete($zone, $plan, $existing, $state, $key);
    }

    private function ensureRoute(
        string $zone,
        string $pattern,
        ?string $script,
        ?array $route,
        array &$state,
        string $key
    ): array {
        if ($route !== null && ($route['script'] ?? null) === $script) {
            return $route;
        }

        $path = '/zones/' . $zone . '/workers/routes';
        $body = ['pattern' => $pattern];
        if ($script !== null) {
            $body['script'] = $script;
        }
        $response = $route === null
            ? $this->api->request('POST', $path, $body)
            : $this->api->request('PUT', $path . '/' . rawurlencode($route['id']), $body);
        $id = $response['result']['id'] ?? '';
        if ($id === '') {
            throw new CloudflareApiException(__('Cloudflare did not return an ID for the new route.'));
        }
        $state['routes'][$zone][$id] = $body;
        $this->flags->saveFlag($key, $state);

        return ['id' => $id] + $body;
    }
}
