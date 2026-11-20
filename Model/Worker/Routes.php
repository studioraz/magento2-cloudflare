<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Worker;

use Magento\Framework\FlagManager;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;

class Routes
{
    public function __construct(
        private readonly CloudflareApi $api,
        private readonly FlagManager $flags
    ) {
    }

    public function getExisting(string $zone): array
    {
        $routes = $this->api->request('GET', '/zones/' . $zone . '/workers/routes')['result'] ?? null;
        if (!is_array($routes)) {
            throw new CloudflareApiException(__('Cloudflare returned an invalid Worker route list.'));
        }
        return array_column($routes, null, 'pattern');
    }

    public function isOwned(array $route, array $owned): bool
    {
        $record = $owned[$route['id'] ?? ''] ?? null;
        return $record !== null
            && ($record['pattern'] ?? null) === $route['pattern']
            && ($record['script'] ?? null) === ($route['script'] ?? null);
    }

    public function removeObsolete(string $zone, array $plan, array $existing, array &$state, string $key): void
    {
        $path = '/zones/' . $zone . '/workers/routes';
        $owned = $state['routes'][$zone] ?? [];
        foreach ($existing as $pattern => $route) {
            if (array_key_exists($pattern, $plan) || !$this->isOwned($route, $owned)) {
                continue;
            }
            $this->api->request('DELETE', $path . '/' . rawurlencode($route['id']));
            unset($state['routes'][$zone][$route['id']]);
            $this->flags->saveFlag($key, $state);
        }
    }
}
