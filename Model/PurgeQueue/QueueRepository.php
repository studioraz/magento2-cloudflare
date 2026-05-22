<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\PurgeQueue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

class QueueRepository
{
    public const TABLE_NAME = 'studioraz_cloudflare_purge_queue';
    public const TYPE_TAGS = 'tags';
    public const TYPE_FILES = 'files';
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';

    private ?bool $tableAvailable = null;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param string[] $tags
     */
    public function enqueueTags(array $tags): bool
    {
        return $this->enqueue(self::TYPE_TAGS, $tags);
    }

    /**
     * @param string[] $urls
     */
    public function enqueueFiles(array $urls): bool
    {
        return $this->enqueue(self::TYPE_FILES, $urls);
    }

    /**
     * @param string[] $values
     */
    public function enqueue(string $type, array $values): bool
    {
        $values = $this->normalizeValues($type, $values);

        if (empty($values)) {
            return true;
        }

        if (!$this->isTableAvailable()) {
            return false;
        }

        $now = $this->dateTime->gmtDate();
        $rows = [];

        foreach ($values as $value) {
            $rows[] = [
                'purge_type' => $type,
                'purge_value' => $value,
                'purge_value_hash' => hash('sha256', $type . ':' . $value),
                'status' => self::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt_at' => null,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        try {
            $this->getConnection()->insertOnDuplicate(
                $this->getTableName(),
                $rows,
                ['status', 'attempts', 'next_attempt_at', 'last_error', 'updated_at']
            );
        } catch (\Exception) {
            $this->tableAvailable = false;
            return false;
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPendingRows(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        if (!$this->isTableAvailable()) {
            return [];
        }

        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTableName())
            ->where('status = ?', self::STATUS_PENDING)
            ->where('next_attempt_at IS NULL OR next_attempt_at <= ?', $this->dateTime->gmtDate())
            ->order(['purge_type ASC', 'queue_id ASC'])
            ->limit($limit);

        return $connection->fetchAll($select);
    }

    /**
     * @param int[] $queueIds
     */
    public function markComplete(array $queueIds): void
    {
        $queueIds = $this->normalizeIds($queueIds);

        if (empty($queueIds)) {
            return;
        }

        if (!$this->isTableAvailable()) {
            return;
        }

        $this->getConnection()->delete(
            $this->getTableName(),
            ['queue_id IN (?)' => $queueIds]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function markRetry(array $rows, string $error, int $maxAttempts, ?int $retryAfter = null): void
    {
        if (empty($rows)) {
            return;
        }

        if (!$this->isTableAvailable()) {
            return;
        }

        $failedIds = [];
        $retryIds = [];

        foreach ($rows as $row) {
            $queueId = (int) ($row['queue_id'] ?? 0);
            if ($queueId <= 0) {
                continue;
            }

            if (((int) ($row['attempts'] ?? 0) + 1) >= $maxAttempts) {
                $failedIds[] = $queueId;
            } else {
                $retryIds[] = $queueId;
            }
        }

        if (!empty($retryIds)) {
            $this->updateForRetry($retryIds, $rows, $error, $retryAfter);
        }

        if (!empty($failedIds)) {
            $this->updateRows($failedIds, [
                'status' => self::STATUS_FAILED,
                'attempts' => new \Zend_Db_Expr('attempts + 1'),
                'last_error' => $this->truncateError($error),
                'updated_at' => $this->dateTime->gmtDate(),
            ]);
        }
    }

    /**
     * @param int[] $queueIds
     * @param array<int, array<string, mixed>> $rows
     */
    private function updateForRetry(array $queueIds, array $rows, string $error, ?int $retryAfter): void
    {
        $this->updateRows($queueIds, [
            'status' => self::STATUS_PENDING,
            'attempts' => new \Zend_Db_Expr('attempts + 1'),
            'next_attempt_at' => $this->dateTime->gmtDate(
                null,
                time() + $this->getRetryDelay($rows, $retryAfter)
            ),
            'last_error' => $this->truncateError($error),
            'updated_at' => $this->dateTime->gmtDate(),
        ]);
    }

    /**
     * @param int[] $queueIds
     * @param array<string, mixed> $bind
     */
    private function updateRows(array $queueIds, array $bind): void
    {
        $queueIds = $this->normalizeIds($queueIds);

        if (empty($queueIds)) {
            return;
        }

        $this->getConnection()->update(
            $this->getTableName(),
            $bind,
            ['queue_id IN (?)' => $queueIds]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function getRetryDelay(array $rows, ?int $retryAfter): int
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            return min($retryAfter, 900);
        }

        $attempts = 0;
        foreach ($rows as $row) {
            $attempts = max($attempts, (int) ($row['attempts'] ?? 0));
        }

        return min(max(60, 2 ** min($attempts, 8)), 900);
    }

    /**
     * @param mixed[] $queueIds
     * @return int[]
     */
    private function normalizeIds(array $queueIds): array
    {
        $ids = array_map('intval', $queueIds);
        $ids = array_filter($ids, static fn(int $id): bool => $id > 0);

        return array_values(array_unique($ids));
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

            $normalized[] = $type === self::TYPE_TAGS ? strtolower($value) : $value;
        }

        return array_values(array_unique($normalized));
    }

    private function truncateError(string $error): string
    {
        return mb_substr($error, 0, 1024);
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }

    private function isTableAvailable(): bool
    {
        if ($this->tableAvailable === null) {
            $this->tableAvailable = $this->getConnection()->isTableExists($this->getTableName());
        }

        return $this->tableAvailable;
    }

    private function getTableName(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE_NAME);
    }
}
