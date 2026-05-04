<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\TradeazeEndpoints\Quote;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\GetDeliveryQuote;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\GetDeliveryQuoteWithoutPickup;
use Tradeaze\ApiIntegration\Model\TradeazeEndpoints\Quote\QuoteStrategyResolver;

class QuoteStrategyResolverTest extends TestCase
{
    private QuoteStrategyResolver $resolver;
    private GetDeliveryQuote&MockObject $withPickup;
    private GetDeliveryQuoteWithoutPickup&MockObject $withoutPickup;
    private Config&MockObject $config;

    protected function setUp(): void
    {
        $this->withPickup = $this->createMock(GetDeliveryQuote::class);
        $this->withoutPickup = $this->createMock(GetDeliveryQuoteWithoutPickup::class);
        $this->config = $this->createMock(Config::class);

        $this->resolver = new QuoteStrategyResolver(
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

    public function testResolveReturnsWithoutPickupWhenDraftMode(): void
    {
        $this->config->method('canCreateDraftOrders')->willReturn(true);

        $this->assertSame($this->withoutPickup, $this->resolver->resolve());
    }
}
