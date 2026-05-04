<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\Cache;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\FrontendInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Model\Cache\Tradeaze;

class TradeazeTest extends TestCase
{
    private Tradeaze $cache;
    private FrontendInterface&MockObject $frontend;

    protected function setUp(): void
    {
        $this->frontend = $this->createMock(FrontendInterface::class);

        $frontendPool = $this->createMock(FrontendPool::class);
        $frontendPool->method('get')
            ->with('tradeaze')
            ->willReturn($this->frontend);

        $this->cache = new Tradeaze($frontendPool);
    }

    public function testLoadResponseFromCacheReturnsArrayOnHit(): void
    {
        $body = '{"postcode":"SW1A1AA"}';
        $cachedData = ['method' => 'CAR', 'price' => 9.99];

        $this->frontend->method('load')
            ->with(hash('sha256', $body))
            ->willReturn(json_encode($cachedData));

        $result = $this->cache->loadResponseFromCache($body);

        $this->assertSame($cachedData, $result);
    }

    public function testLoadResponseFromCacheReturnsFalseOnMiss(): void
    {
        $body = '{"postcode":"SW1A1AA"}';

        $this->frontend->method('load')
            ->with(hash('sha256', $body))
            ->willReturn(false);

        $this->assertFalse($this->cache->loadResponseFromCache($body));
    }

    public function testSaveResponseToCacheSavesWithCorrectKeyAndTag(): void
    {
        $body = '{"postcode":"SW1A1AA"}';
        $response = ['method' => 'CAR', 'price' => 9.99];
        $expectedKey = hash('sha256', $body);

        $this->frontend->expects($this->once())
            ->method('save')
            ->with(
                json_encode($response),
                $expectedKey,
                ['TRADEAZE'],
                300
            );

        $this->cache->saveResponseToCache($body, $response);
    }

    public function testCacheKeyIsDeterministic(): void
    {
        $body = '{"items":[{"sku":"ABC"}]}';
        $expectedKey = hash('sha256', $body);

        $this->frontend->expects($this->exactly(2))
            ->method('load')
            ->with($expectedKey)
            ->willReturn(false);

        $this->cache->loadResponseFromCache($body);
        $this->cache->loadResponseFromCache($body);
    }

    public function testDifferentBodiesProduceDifferentKeys(): void
    {
        $keys = [];

        $this->frontend->method('load')
            ->willReturnCallback(function ($key) use (&$keys) {
                $keys[] = $key;
                return false;
            });

        $this->cache->loadResponseFromCache('{"a":1}');
        $this->cache->loadResponseFromCache('{"a":2}');

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }
}
