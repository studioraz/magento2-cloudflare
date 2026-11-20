<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\Worker;

use Magento\Framework\Exception\LocalizedException;

class RoutePlanner
{
    public const DEFAULT_BYPASS_PATTERNS = [
        '/static/*',
        '/media/*',
        '/pub/static/*',
        '/pub/media/*',
        '/cdn-cgi/*',
        '/rest/*',
    ];

    public static function isValidBypassPath(string $path): bool
    {
        return preg_match('#^/[a-zA-Z0-9._~/%-]+$#D', $path) === 1
            && preg_match('#^/+(?:$|customer(?:/|$))#i', $path) === 0;
    }

    /** @return array<string, string|null> */
    public function plan(
        string $domain,
        string $worker,
        string $adminHost,
        string $adminPath,
        bool $dedicatedAdminHost,
        string $bypassPaths
    ): array {
        $main = [];
        $bypass = [];
        $patterns = self::DEFAULT_BYPASS_PATTERNS;
        foreach (explode(',', $bypassPaths) as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            if (!self::isValidBypassPath($path)) {
                throw new LocalizedException(__('Enter valid Worker bypass paths and keep the customer routes active.'));
            }
            $patterns[] = '/' . trim($path, '/') . '/*';
        }

        foreach ([$domain, '*.' . $domain] as $host) {
            $main[$host . '/*'] = $worker;
            foreach ($patterns as $pattern) {
                $bypass[$host . $pattern] = null;
            }
        }

        $adminRoute = $this->getAdminBypassPattern($domain, $adminHost, $adminPath, $dedicatedAdminHost);
        if ($adminRoute !== null) {
            $bypass[$adminRoute] = null;
        }

        return $bypass + $main;
    }

    public function getAdminBypassPattern(
        string $domain,
        string $adminHost,
        string $adminPath,
        bool $dedicatedAdminHost
    ): ?string {
        if ($adminHost !== $domain && !str_ends_with($adminHost, '.' . $domain)) {
            return null;
        }

        return self::formatAdminBypassPattern($adminHost, $adminPath, $dedicatedAdminHost);
    }

    public static function formatAdminBypassPattern(string $host, string $path, bool $dedicatedHost): string
    {
        // Match the entry URL as well as nested admin paths and query strings.
        return $host . ($dedicatedHost ? '/*' : $path . '*');
    }
}
