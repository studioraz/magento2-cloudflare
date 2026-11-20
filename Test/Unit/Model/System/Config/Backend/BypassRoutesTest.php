<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Model\System\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Model\System\Config\Backend\BypassRoutes;

class BypassRoutesTest extends TestCase
{
    private BypassRoutes $backend;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $this->backend = new BypassRoutes(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class)
        );
    }

    public function testLoadsStoredPathsAsRows(): void
    {
        $this->backend->setValue(" /api, \r\n/rest\n /feeds/ ")->afterLoad();

        self::assertSame([
            '_route_0' => ['path' => '/api'],
            '_route_1' => ['path' => '/rest'],
            '_route_2' => ['path' => '/feeds/'],
        ], $this->backend->getValue());
    }

    public function testSavesEditedRowsInExistingStorageFormat(): void
    {
        $this->backend->setValue([
            '__empty' => '',
            '_route_0' => ['path' => ' /rest '],
            '_new_route' => ['path' => '/feeds'],
        ])->beforeSave();

        self::assertSame('/rest,/feeds', $this->backend->getValue());
    }

    public function testDeletingAllRowsClearsAdditionalRoutes(): void
    {
        $this->backend->setValue(['__empty' => ''])->beforeSave()->afterLoad();

        self::assertSame([], $this->backend->getValue());
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsInvalidRows(string $path): void
    {
        $this->expectException(LocalizedException::class);
        $this->backend->setValue(['_route_0' => ['path' => $path]])->beforeSave();
    }

    public static function invalidPaths(): array
    {
        return [
            'multiple paths' => ['/api,/rest'],
            'customer handler' => ['/customer/account/logout'],
            'absolute URL' => ['https://example.com/api'],
        ];
    }
}
