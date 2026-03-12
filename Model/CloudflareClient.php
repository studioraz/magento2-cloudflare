<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model;

use Laminas\Http\Request as HttpRequest;
use SR\Gateway\Api\Http\Client\ClientInterface;
use SR\Gateway\Api\LoggerInterface;
use SR\Gateway\Model\Http\TransferBuilderFactory;

class CloudflareClient
{
    private const MAX_TAGS_PER_REQUEST = 30;

    public function __construct(
        private readonly \SR\Cloudflare\Config\CacheConfig $config,
        private readonly ClientInterface                  $restClient,
        private readonly TransferBuilderFactory           $transferBuilderFactory,
        private readonly LoggerInterface                  $logger
    ) {
    }

    /**
     * Purge cached pages by cache tags.
     *
     * @param string[] $tags
     */
    public function purgeByTags(array $tags): void
    {
        $tags = array_values(array_unique($tags));

        if (empty($tags)) {
            return;
        }

        foreach (array_chunk($tags, self::MAX_TAGS_PER_REQUEST) as $batch) {
            $this->sendPurgeRequest(['tags' => $batch]);
        }
    }

    /**
     * Purge all cached pages by site-wide tag (hostname).
     *
     * Uses tag-based purge instead of purge_everything
     * to avoid clearing Cloudflare Image Transformations cache.
     */
    public function purgeAll(): void
    {
        $siteTag = $this->config->getSiteTag();
        $this->sendPurgeRequest(['tags' => [$siteTag]]);
    }

    /**
     * Purge cached pages by URLs (fallback method).
     *
     * @param string[] $urls
     */
    public function purgeByUrls(array $urls): void
    {
        $urls = array_values(array_unique($urls));

        if (empty($urls)) {
            return;
        }

        foreach (array_chunk($urls, self::MAX_TAGS_PER_REQUEST) as $batch) {
            $this->sendPurgeRequest(['files' => $batch]);
        }
    }

    private function sendPurgeRequest(array $body): void
    {
        if (!$this->config->isConfigured()) {
            return;
        }

        $url = $this->config->getResolvedApiUrl();

        try {
            $transfer = $this->transferBuilderFactory->create()
                ->setMethod(HttpRequest::METHOD_POST)
                ->setUri($url)
                ->setHeaders([
                    'Authorization' => 'Bearer ' . $this->config->getApiToken(),
                    'Content-Type'  => 'application/json',
                ])
                ->setBody($body)
                ->shouldEncode(true)
                ->build();

            $this->restClient->placeRequest($transfer);
        } catch (\Exception $e) {
            $this->logger->error(
                'SR_Cloudflare: Purge API request failed: ' . $e->getMessage()
            );
        }
    }
}
