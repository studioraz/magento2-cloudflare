<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Config;

use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;

class CacheConfigTest extends TestCase
{
    #[DataProvider('fpcTtlValues')]
    public function testWorkerTtlReadsOnlyGlobalFpcConfiguration(?string $ttl, int $expected): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())->method('getValue')
            ->with(PageCacheConfig::XML_PAGECACHE_TTL, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null)
            ->willReturn($ttl);
        $config = new CacheConfig(
            $scopeConfig,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StateInterface::class)
        );

        self::assertSame($expected, $config->getWorkerTtl());
    }

    public static function fpcTtlValues(): array
    {
        return [
            'configured TTL' => ['86411', 86411],
            'zero TTL' => ['0', 0],
            'empty TTL' => ['', 86400],
            'missing TTL' => [null, 86400],
        ];
    }
}
