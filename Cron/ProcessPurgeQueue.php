<?php

declare(strict_types=1);

namespace SR\Cloudflare\Cron;

use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\PurgeException;
use SR\Cloudflare\Exception\RetryablePurgeException;
use SR\Cloudflare\Model\CloudflareClient;
use SR\Cloudflare\Model\PurgeQueue\QueueRepository;
use SR\Gateway\Api\LoggerInterface;

class ProcessPurgeQueue
{
    private const CLOUDFLARE_PRO_REQUESTS_PER_SECOND = 5;

    public function __construct(
        private readonly CacheConfig $config,
        private readonly QueueRepository $queueRepository,
        private readonly CloudflareClient $cloudflareClient,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isConfigured() || !$this->config->isAsyncPurgeEnabled()) {
            return;
        }

        $batchSize = $this->config->getPurgeBatchSize();
        $maxRequests = $this->config->getPurgeMaxRequestsPerCronRun();
        $rows = $this->queueRepository->getPendingRows($batchSize * $maxRequests);

        if (empty($rows)) {
            return;
        }

        $requestsSent = 0;
        $rateLimited = [];
        foreach ($this->createBatches($rows, $batchSize) as $batch) {
            if ($requestsSent >= $maxRequests) {
                break;
            }

            $zoneId = (string) $batch[0]['zone_id'];
            if (isset($rateLimited[$zoneId])) {
                continue;
            }

            $this->throttleBeforeRequest($requestsSent);
            $requestsSent++;
            if (!$this->processBatch($batch, $zoneId)) {
                $rateLimited[$zoneId] = true;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function processBatch(array $batch, string $zoneId): bool
    {
        $type = (string) ($batch[0]['purge_type'] ?? '');
        $values = array_map(static fn(array $row): string => (string) $row['purge_value'], $batch);
        $queueIds = array_map(static fn(array $row): int => (int) $row['queue_id'], $batch);

        try {
            if ($zoneId === '') {
                // Existing jobs did not record a zone. Expand them before attempting any API requests.
                if (!$this->queueRepository->enqueue($type, $values)) {
                    throw new PurgeException('Could not assign existing purge jobs to Cloudflare zones.');
                }
            } else {
                $this->cloudflareClient->purgeByType($type, $values, $zoneId);
            }
            $this->queueRepository->markComplete($queueIds);
            return true;
        } catch (RetryablePurgeException $exception) {
            $this->queueRepository->markRetry(
                $batch,
                $exception->getMessage(),
                $this->config->getPurgeMaxAttempts(),
                $exception->getRetryAfter()
            );
            $this->logger->error('SR_Cloudflare: Rate limited while processing purge queue: ' . $exception->getMessage());
            return false;
        } catch (PurgeException $exception) {
            $this->queueRepository->markRetry(
                $batch,
                $exception->getMessage(),
                $this->config->getPurgeMaxAttempts()
            );
            $this->logger->error('SR_Cloudflare: Failed to process purge queue batch: ' . $exception->getMessage());
            return true;
        } catch (\Throwable $exception) {
            $this->queueRepository->markRetry(
                $batch,
                $exception->getMessage(),
                $this->config->getPurgeMaxAttempts()
            );
            $this->logger->error('SR_Cloudflare: Unexpected purge queue failure: ' . $exception->getMessage());
            return true;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function createBatches(array $rows, int $batchSize): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['zone_id'] . ':' . $row['purge_type']][] = $row;
        }

        $batches = [];
        foreach ($groups as $group) {
            array_push($batches, ...array_chunk($group, $batchSize));
        }

        return $batches;
    }

    private function throttleBeforeRequest(int $requestsSent): void
    {
        if ($requestsSent < CacheConfig::CLOUDFLARE_PRO_PURGE_BUCKET_SIZE) {
            return;
        }

        usleep((int) (1000000 / self::CLOUDFLARE_PRO_REQUESTS_PER_SECOND));
    }
}
