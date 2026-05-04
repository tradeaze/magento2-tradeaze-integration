<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Helper\Config;

class ConfigTest extends TestCase
{
    private Config $config;
    private ScopeConfigInterface&MockObject $scopeConfig;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);

        $context = $this->createMock(Context::class);
        $context->method('getScopeConfig')->willReturn($this->scopeConfig);

        $this->config = new Config($context);
    }

    public function testIsEnabledReturnsTrueWhenEnabledAndTokenSet(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tradeaze_api/general/enabled', 'store', null, '1'],
                ['tradeaze_api/general/api_token', 'store', null, 'test-token'],
            ]);

        $this->assertTrue($this->config->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tradeaze_api/general/enabled', 'store', null, '0'],
                ['tradeaze_api/general/api_token', 'store', null, 'test-token'],
            ]);

        $this->assertFalse($this->config->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenNoToken(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tradeaze_api/general/enabled', 'store', null, '1'],
                ['tradeaze_api/general/api_token', 'store', null, null],
            ]);

        $this->assertFalse($this->config->isEnabled());
    }

    public function testGetApiUrlReturnsLiveUrl(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('tradeaze_api/general/api_mode', 'store', null)
            ->willReturn('live');

        $this->assertSame('https://api.tradeaze.app', $this->config->getApiUrl());
    }

    public function testGetApiUrlReturnsStageUrl(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('tradeaze_api/general/api_mode', 'store', null)
            ->willReturn('test');

        $this->assertSame('https://stage-api.tradeaze.app', $this->config->getApiUrl());
    }

    public function testGetApiTokenReturnsToken(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('tradeaze_api/general/api_token', 'store', null)
            ->willReturn('my-secret-token');

        $this->assertSame('my-secret-token', $this->config->getAPIToken());
    }

    public function testGetApiTokenReturnsEmptyStringWhenNull(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('tradeaze_api/general/api_token', 'store', null)
            ->willReturn(null);

        $this->assertSame('', $this->config->getAPIToken());
    }

    public function testCanCreateDraftOrders(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('tradeaze_api/general/create_draft_orders', 'store', null)
            ->willReturn('1');

        $this->assertTrue($this->config->canCreateDraftOrders());
    }

    public function testGetProductAttributesToCheckReturnsArrayWhenAllSet(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tradeaze_api/product_attributes/tradeaze_length_attribute', 'store', null, 'ts_length'],
                ['tradeaze_api/product_attributes/tradeaze_width_attribute', 'store', null, 'ts_width'],
                ['tradeaze_api/product_attributes/tradeaze_height_attribute', 'store', null, 'ts_height'],
                ['tradeaze_api/product_attributes/tradeaze_weight_attribute', 'store', null, 'ts_weight'],
            ]);

        $result = $this->config->getProductAttributesToCheck();

        $this->assertIsArray($result);
        $this->assertSame('ts_length', $result['length']);
        $this->assertSame('ts_width', $result['width']);
        $this->assertSame('ts_height', $result['height']);
        $this->assertSame('ts_weight', $result['weight']);
    }

    public function testGetProductAttributesToCheckReturnsFalseWhenIncomplete(): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnMap([
                ['tradeaze_api/product_attributes/tradeaze_length_attribute', 'store', null, 'ts_length'],
                ['tradeaze_api/product_attributes/tradeaze_width_attribute', 'store', null, ''],
                ['tradeaze_api/product_attributes/tradeaze_height_attribute', 'store', null, 'ts_height'],
                ['tradeaze_api/product_attributes/tradeaze_weight_attribute', 'store', null, 'ts_weight'],
            ]);

        $this->assertFalse($this->config->getProductAttributesToCheck());
    }

    public function testIsPDPMessagingEnabled(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('tradeaze_api/messaging/pdp_messaging_enabled', 'store', null)
            ->willReturn('1');

        $this->assertTrue($this->config->isPDPMessagingEnabled());
    }

    public function testIsBasketMessagingEnabled(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('tradeaze_api/messaging/basket_messaging_enabled', 'store', null)
            ->willReturn('0');

        $this->assertFalse($this->config->isBasketMessagingEnabled());
    }

    public function testGetDeliveryCutoffTimeBuffer(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('tradeaze_api/delivery/delivery_cutoff_time_buffer', 'store', null)
            ->willReturn('15');

        $this->assertSame('15', $this->config->getDeliveryCutoffTimeBuffer());
    }

    public function testGetScopeConfigValue(): void
    {
        $this->scopeConfig->method('getValue')
            ->with('web/secure/base_url', 'store', null)
            ->willReturn('https://example.com/');

        $this->assertSame('https://example.com/', $this->config->getScopeConfigValue('web/secure/base_url'));
    }
}
