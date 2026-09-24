<?php

declare(strict_types=1);

namespace SR\Cloudflare\Test\Unit\Plugin;

use Magento\Framework\App\Response\Http;
use Magento\Framework\Controller\ResultInterface;
use PHPUnit\Framework\TestCase;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Plugin\AddCacheTagHeader;

class AddCacheTagHeaderTest extends TestCase
{
    private Http $response;
    private AddCacheTagHeader $plugin;

    protected function setUp(): void
    {
        $this->response = $this->getMockBuilder(Http::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $config = $this->createMock(CacheConfig::class);
        $config->method('isCloudflareApplication')->willReturn(true);
        $config->method('getSiteTag')->willReturn('example_test');
        $this->plugin = new AddCacheTagHeader($config);
    }

    public function testDirectResponseReceivesTagsWithoutChangingContentOrCachePolicy(): void
    {
        $this->response->setHeader('X-Magento-Tags', 'cat_p_42,cat_p');
        $this->response->setHeader('Cache-Control', 'public, s-maxage=60');
        $this->response->setHeader('Content-Type', 'application/json');
        $this->response->setBody('{"success":true}');

        $this->plugin->beforeSendResponse($this->response);

        self::assertSame('example_test,cat_p_42,cat_p', $this->response->getHeader('Cache-Tag')->getFieldValue());
        self::assertSame('public, s-maxage=60', $this->response->getHeader('Cache-Control')->getFieldValue());
        self::assertSame('application/json', $this->response->getHeader('Content-Type')->getFieldValue());
        self::assertSame('{"success":true}', $this->response->getBody());
    }

    public function testFinalHookIncludesLateTagsWithoutDuplicatingTheHeader(): void
    {
        $result = $this->createMock(ResultInterface::class);
        $this->response->setHeader('X-Magento-Tags', 'cat_p_42');
        self::assertSame($result, $this->plugin->afterRenderResult($result, $result, $this->response));

        $this->response->setHeader('X-Magento-Tags', 'cat_p_42,cat_p_43', true);
        $this->plugin->beforeSendResponse($this->response);
        $this->plugin->beforeSendResponse($this->response);

        self::assertSame('example_test,cat_p_42,cat_p_43', $this->response->getHeader('Cache-Tag')->getFieldValue());
        $cacheTagHeaders = [];
        foreach ($this->response->getHeaders() as $header) {
            if (strcasecmp($header->getFieldName(), 'Cache-Tag') === 0) {
                $cacheTagHeaders[] = $header;
            }
        }
        self::assertCount(1, $cacheTagHeaders);
    }

    public function testFinalHookPreservesEarlierTagsWhenMagentoTagsWereRemoved(): void
    {
        $result = $this->createMock(ResultInterface::class);
        $this->response->setHeader('X-Magento-Tags', 'cat_p_42');
        $this->plugin->afterRenderResult($result, $result, $this->response);
        $this->response->clearHeader('X-Magento-Tags');

        $this->plugin->beforeSendResponse($this->response);

        self::assertSame('example_test,cat_p_42', $this->response->getHeader('Cache-Tag')->getFieldValue());
    }

    public function testFinalHookDoesNotAddTagsForOtherCachingApplications(): void
    {
        $config = $this->createMock(CacheConfig::class);
        $config->method('isCloudflareApplication')->willReturn(false);
        $config->expects(self::never())->method('getSiteTag');
        $this->response->setHeader('X-Magento-Tags', 'cat_p_42');

        (new AddCacheTagHeader($config))->beforeSendResponse($this->response);

        self::assertFalse($this->response->getHeader('Cache-Tag'));
    }
}
