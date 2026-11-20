<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Cron\ProcessPurgeQueue;
use SR\Cloudflare\Exception\RetryablePurgeException;
use SR\Cloudflare\Model\CloudflareClient;
use SR\Cloudflare\Model\PurgeQueue\QueueRepository;
use SR\Gateway\Api\LoggerInterface;

class ProcessPurgeQueueTest extends TestCase
{
    public function testRateLimitedZoneDoesNotBlockOtherZones(): void
    {
        $config = $this->createMock(CacheConfig::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isAsyncPurgeEnabled')->willReturn(true);
        $config->method('getPurgeBatchSize')->willReturn(1);
        $config->method('getPurgeMaxRequestsPerCronRun')->willReturn(3);
        $config->method('getPurgeMaxAttempts')->willReturn(5);

        $rows = [
            ['queue_id' => 1, 'zone_id' => 'zone-a', 'purge_type' => 'tags', 'purge_value' => 'cat_p_42'],
            ['queue_id' => 2, 'zone_id' => 'zone-b', 'purge_type' => 'tags', 'purge_value' => 'cat_p_42'],
            ['queue_id' => 3, 'zone_id' => 'zone-a', 'purge_type' => 'tags', 'purge_value' => 'cat_p_43'],
        ];
        $queue = $this->createMock(QueueRepository::class);
        $queue->method('getPendingRows')->willReturn($rows);
        $queue->expects(self::once())->method('markRetry')->with([$rows[0]], 'Rate limited', 5, 15);
        $queue->expects(self::once())->method('markComplete')->with([2]);

        $client = $this->createMock(CloudflareClient::class);
        $client->expects(self::exactly(2))->method('purgeByType')->willReturnCallback(
            static function (string $type, array $values, string $zone): void {
                self::assertSame('tags', $type);
                self::assertSame(['cat_p_42'], $values);
                if ($zone === 'zone-a') {
                    throw new RetryablePurgeException('Rate limited', 15);
                }
                self::assertSame('zone-b', $zone);
            }
        );

        (new ProcessPurgeQueue($config, $queue, $client, $this->createMock(LoggerInterface::class)))->execute();
    }
}
