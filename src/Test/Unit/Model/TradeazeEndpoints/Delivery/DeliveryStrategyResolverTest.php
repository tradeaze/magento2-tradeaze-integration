<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\TradeazeEndpoints\Delivery;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\CreateDelivery;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\CreateDraftOrder;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Delivery\DeliveryStrategyResolver;

class DeliveryStrategyResolverTest extends TestCase
{
    private DeliveryStrategyResolver $resolver;
    private CreateDelivery&MockObject $withPickup;
    private CreateDraftOrder&MockObject $withoutPickup;
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->withPickup = $this->createMock(CreateDelivery::class);
        $this->withoutPickup = $this->createMock(CreateDraftOrder::class);
        $this->config = $this->createMock(Config::class);

        $this->resolver = new DeliveryStrategyResolver(
            $this->withPickup,
            $this->withoutPickup,
            $this->config
        );
    }

    public function testResolveReturnsWithPickupWhenNotDraftMode(): void
    {
        $this->config->method('canCreateDraftOrders')->willReturn(false);

        $this->assertSame($this->withPickup, $this->resolver->resolve());
    }

    public function testResolveReturnsDraftOrderWhenDraftMode(): void
    {
        $this->config->method('canCreateDraftOrders')->willReturn(true);

        $this->assertSame($this->withoutPickup, $this->resolver->resolve());
    }
}
