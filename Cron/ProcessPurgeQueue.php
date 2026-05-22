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
        foreach ($this->createBatches($rows, $batchSize) as $batch) {
            if ($requestsSent >= $maxRequests) {
                break;
            }

            $this->throttleBeforeRequest($requestsSent);

            if ($this->processBatch($batch)) {
                $requestsSent++;
                continue;
            }

            break;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function processBatch(array $batch): bool
    {
        $type = (string) ($batch[0]['purge_type'] ?? '');
        $values = array_map(static fn(array $row): string => (string) $row['purge_value'], $batch);
        $queueIds = array_map(static fn(array $row): int => (int) $row['queue_id'], $batch);

        try {
            $this->cloudflareClient->purgeByType($type, $values);
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
        $batches = [];
        $currentType = null;
        $currentBatch = [];

        foreach ($rows as $row) {
            $type = (string) ($row['purge_type'] ?? '');

            if ($currentBatch !== [] && ($type !== $currentType || count($currentBatch) >= $batchSize)) {
                $batches[] = $currentBatch;
                $currentBatch = [];
            }

            $currentType = $type;
            $currentBatch[] = $row;
        }

        if ($currentBatch !== []) {
            $batches[] = $currentBatch;
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
