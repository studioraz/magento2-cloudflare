<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model;

use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Exception\PurgeException;
use SR\Cloudflare\Exception\RetryablePurgeException;
use SR\Gateway\Api\LoggerInterface;

class CloudflareClient
{
    private const MAX_OPERATIONS_PER_REQUEST = 100;
    private const PURGE_TYPE_TAGS = 'tags';
    private const PURGE_TYPE_FILES = 'files';

    public function __construct(
        private readonly CacheConfig $config,
        private readonly CloudflareApi $api,
        private readonly ZoneDirectory $directory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Purge cached pages by cache tags.
     *
     * @param string[] $tags
     */
    public function purgeByTags(array $tags): void
    {
        $this->purgeByType(self::PURGE_TYPE_TAGS, $tags);
    }

    /**
     * Purge all cached pages by site-wide tag (hostname).
     *
     * Uses tag-based purge instead of purge_everything
     * to avoid clearing Cloudflare Image Transformations cache.
     */
    public function purgeAll(): void
    {
        $siteTags = $this->config->getAllSiteTags();
        $this->purgeByTags($siteTags);
    }

    /**
     * Purge cached pages by URLs (fallback method).
     *
     * @param string[] $urls
     */
    public function purgeByUrls(array $urls): void
    {
        $this->purgeByType(self::PURGE_TYPE_FILES, $urls);
    }

    /**
     * @param string[] $values
     */
    public function purgeByType(string $type, array $values, ?string $zoneId = null): void
    {
        if (!in_array($type, [self::PURGE_TYPE_TAGS, self::PURGE_TYPE_FILES], true)) {
            throw new PurgeException(sprintf('Unsupported Cloudflare purge type "%s"', $type));
        }

        $values = $this->normalizeValues($type, $values);

        if (empty($values)) {
            return;
        }

        if (!$this->config->isConfigured()) {
            return;
        }

        if ($zoneId !== null && !$this->directory->shouldPurgeZone($zoneId)) {
            return;
        }
        $zoneIds = $zoneId === null ? $this->directory->getPurgeZoneIds() : [$zoneId];
        $batches = array_chunk($values, self::MAX_OPERATIONS_PER_REQUEST);
        $firstFailure = null;
        foreach ($zoneIds as $id) {
            try {
                foreach ($batches as $batch) {
                    $this->sendPurgeRequest($id, [$type => $batch]);
                }
            } catch (PurgeException $exception) {
                $firstFailure ??= $exception;
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }

    private function sendPurgeRequest(string $zoneId, array $body): void
    {
        try {
            $this->api->request('POST', '/zones/' . rawurlencode($zoneId) . '/purge_cache', $body);
        } catch (CloudflareApiException $exception) {
            $message = $exception->getMessage();
            $this->logger->error('SR_Cloudflare: Purge API request failed: ' . $message);
            if ($exception->isRateLimited()) {
                throw new RetryablePurgeException($message, $exception->getRetryAfter(), 0, $exception);
            }
            throw new PurgeException($message, 0, $exception);
        }
    }

    /**
     * @param mixed[] $values
     * @return string[]
     */
    private function normalizeValues(string $type, array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $normalized[] = $type === self::PURGE_TYPE_TAGS ? strtolower($value) : $value;
        }

        return array_values(array_unique($normalized));
    }
}
