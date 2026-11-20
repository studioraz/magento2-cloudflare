<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Plugin\Adminhtml;

use Magento\Config\Controller\Adminhtml\System\Config\Save;
use Magento\Config\Model\Config\Reader\Source\Deployed\SettingChecker;
use Magento\Config\Model\Config\Structure\ElementVisibilityInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\PageCache\Model\Config as PageCacheConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Plugin\Adminhtml\FullPageCacheFields;

class FullPageCacheFieldsTest extends TestCase
{
    #[DataProvider('ttlValues')]
    public function testPreservesTheSubmittedTtlForNativeValidation(array $submitted): void
    {
        $config = $this->makeConfig();
        $config['groups']['cache']['groups']['magento_cache']['fields']['ttl'] = $submitted;

        $result = $this->createPlugin()->afterGetConfigData($this->createMock(Save::class), $config);

        self::assertSame($submitted, $result['groups']['cache']['groups']['magento_cache']['fields']['ttl']);
    }

    public static function ttlValues(): array
    {
        return [
            'empty' => [['value' => '']],
            'zero' => [['value' => '0']],
            'restore' => [['inherit' => '1']],
        ];
    }

    public function testDisablesAliasesForRolesWithoutSystemAccessAndIgnoresTheirSubmittedValues(): void
    {
        $plugin = $this->createPlugin(allowed: false);
        $visibility = $this->createMock(ElementVisibilityInterface::class);
        self::assertTrue(
            $plugin->afterIsDisabled($visibility, false, 'srcloudflare/cache/magento_cache/caching_application')
        );
        self::assertTrue($plugin->afterIsDisabled($visibility, false, 'srcloudflare/cache/magento_cache/ttl'));
        self::assertFalse($plugin->afterIsDisabled($visibility, false, 'srcloudflare/cache/active'));

        $result = $plugin->afterGetConfigData($this->createMock(Save::class), $this->makeConfig());
        self::assertSame(['active' => ['value' => '1']], $result['groups']['cache']['fields']);
        self::assertSame([], $result['groups']['cache']['groups']['magento_cache']['fields']);
    }

    public function testIgnoresWebsiteOverridesAndDeploymentLockedSettings(): void
    {
        $plugin = $this->createPlugin(lockedPath: PageCacheConfig::XML_PAGECACHE_TTL);
        $save = $this->createMock(Save::class);
        $config = $this->makeConfig();

        $result = $plugin->afterGetConfigData($save, $config);
        self::assertSame(
            ['caching_application' => ['value' => '3']],
            $result['groups']['cache']['groups']['magento_cache']['fields']
        );

        $config['website'] = 'base';
        $result = $plugin->afterGetConfigData($save, $config);
        self::assertSame([], $result['groups']['cache']['groups']['magento_cache']['fields']);
    }

    public function testLeavesTheNativeConfigurationPageAlone(): void
    {
        $config = ['section' => 'system', 'groups' => ['full_page_cache' => ['fields' => ['ttl' => ['value' => '600']]]]];

        self::assertSame($config, $this->createPlugin()->afterGetConfigData($this->createMock(Save::class), $config));
    }

    private function makeConfig(): array
    {
        return [
            'section' => 'srcloudflare',
            'groups' => ['cache' => [
                'fields' => ['active' => ['value' => '1']],
                'groups' => ['magento_cache' => ['fields' => [
                    'caching_application' => ['value' => '3'],
                    'ttl' => ['value' => '900'],
                ]]],
            ]],
        ];
    }

    private function createPlugin(bool $allowed = true, ?string $lockedPath = null): FullPageCacheFields
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with('Magento_Config::config_system')->willReturn($allowed);
        $checker = $this->createMock(SettingChecker::class);
        $checker->method('isReadOnly')->willReturnCallback(static fn(string $path): bool => $path === $lockedPath);

        return new FullPageCacheFields($authorization, $checker);
    }
}
