<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Service;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\Country;
use Magento\Directory\Model\CountryFactory;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventorySalesApi\Model\GetAssignedStockIdForWebsiteInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\Product\Attribute\Source\TradeazeSizeCategory;
use Tradeaze\ApiIntegration\Model\Product\Attribute\Source\TradeazeWeightCategory;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class TradeazeTest extends TestCase
{
    private Tradeaze $service;
    private Config&MockObject $config;
    private TradeazeSizeCategory&MockObject $sizeCategoryMapping;
    private TradeazeWeightCategory&MockObject $weightCategoryMapping;
    private CountryFactory&MockObject $countryFactory;
    private ProductResource&MockObject $productResource;
    private DirectoryHelper&MockObject $directoryHelper;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->sizeCategoryMapping = $this->getMockBuilder(TradeazeSizeCategory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->weightCategoryMapping = $this->getMockBuilder(TradeazeWeightCategory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->countryFactory = $this->createMock(CountryFactory::class);
        $this->productResource = $this->getMockBuilder(ProductResource::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->directoryHelper = $this->getMockBuilder(DirectoryHelper::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->service = new Tradeaze(
            $this->config,
            $this->sizeCategoryMapping,
            $this->weightCategoryMapping,
            $this->countryFactory,
            $this->createMock(GetAssignedStockIdForWebsiteInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(StockByWebsiteIdResolverInterface::class),
            $this->createMock(GetSourcesAssignedToStockOrderedByPriorityInterface::class),
            $this->productResource,
            $this->directoryHelper
        );
    }

    private function createProductMock(array $methods = []): Product&MockObject
    {
        return $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->addMethods(['getTradeazeEnabled', 'getTradeazeSizeCategory', 'getTradeazeWeightCategory'])
            ->onlyMethods(['getData', 'getId', 'getStoreId'])
            ->getMock();
    }

    public function testIsTradeazeProductReturnsFalseWhenNotEnabled(): void
    {
        $product = $this->createProductMock();
        $product->method('getTradeazeEnabled')->willReturn(false);

        $this->assertFalse($this->service->isTradeazeProduct($product));
    }

    public function testIsTradeazeProductReturnsTrueWithSizeAndWeightCategories(): void
    {
        $product = $this->createProductMock();
        $product->method('getTradeazeEnabled')->willReturn(true);
        $product->method('getTradeazeSizeCategory')->willReturn('size_medium_box');
        $product->method('getTradeazeWeightCategory')->willReturn('weight_25');

        $this->sizeCategoryMapping->method('getTradeazeSizeMappingFromCategory')
            ->with('size_medium_box')
            ->willReturn([
                'length' => ['value' => 0.3, 'unit' => 'm'],
                'width' => ['value' => 0.3, 'unit' => 'm'],
                'height' => ['value' => 0.3, 'unit' => 'm'],
            ]);
        $this->weightCategoryMapping->method('getTradeazeWeightMappingFromCategory')
            ->with('weight_25')
            ->willReturn(['weight' => ['value' => 25, 'unit' => 'kg']]);

        $this->assertTrue($this->service->isTradeazeProduct($product));
    }

    public function testIsTradeazeProductReturnsFalseWithNoDimensions(): void
    {
        $product = $this->createProductMock();
        $product->method('getTradeazeEnabled')->willReturn(true);
        $product->method('getTradeazeSizeCategory')->willReturn(null);
        $product->method('getTradeazeWeightCategory')->willReturn(null);
        $product->method('getData')->willReturn(null);

        $this->config->method('getProductAttributesToCheck')->willReturn(false);

        $this->assertFalse($this->service->isTradeazeProduct($product));
    }

    public function testIsTradeazeProductReturnsTrueWithAttributeData(): void
    {
        $product = $this->createProductMock();
        $product->method('getTradeazeEnabled')->willReturn(true);
        $product->method('getTradeazeSizeCategory')->willReturn(null);
        $product->method('getTradeazeWeightCategory')->willReturn(null);
        $product->method('getData')->willReturnCallback(fn($key) => match ($key) {
            'ts_length' => '100',
            'ts_width' => '50',
            'ts_height' => '25',
            'ts_weight' => '10',
            default => null
        });

        $this->config->method('getTradeazeLengthAttribute')->willReturn('ts_length');
        $this->config->method('getTradeazeWidthAttribute')->willReturn('ts_width');
        $this->config->method('getTradeazeHeightAttribute')->willReturn('ts_height');
        $this->config->method('getTradeazeWeightAttribute')->willReturn('ts_weight');
        $this->config->method('getTradeazeAttributeDefaultUnit')->willReturn('cm');

        $this->assertTrue($this->service->isTradeazeProduct($product));
    }

    public function testGetSizeAndWeightMappingFromAttributes(): void
    {
        $product = $this->createProductMock();
        $product->method('getId')->willReturn(1);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getTradeazeSizeCategory')->willReturn(null);
        $product->method('getTradeazeWeightCategory')->willReturn(null);
        $product->method('getData')->willReturnCallback(fn($key) => match ($key) {
            'ts_length' => '100',
            'ts_width' => '50',
            'ts_height' => '25',
            'ts_weight' => '10',
            default => null
        });

        $this->config->method('getTradeazeLengthAttribute')->willReturn('ts_length');
        $this->config->method('getTradeazeWidthAttribute')->willReturn('ts_width');
        $this->config->method('getTradeazeHeightAttribute')->willReturn('ts_height');
        $this->config->method('getTradeazeWeightAttribute')->willReturn('ts_weight');
        $this->config->method('getTradeazeAttributeDefaultUnit')->willReturn('cm');
        $this->directoryHelper->method('getWeightUnit')->willReturn('kgs');

        $result = $this->service->getSizeAndWeightMapping($product);

        $this->assertSame(100.0, $result['length']['value']);
        $this->assertSame('cm', $result['length']['unit']);
        $this->assertSame(50.0, $result['width']['value']);
        $this->assertSame(25.0, $result['height']['value']);
        $this->assertSame(10.0, $result['weight']['value']);
        $this->assertSame('kg', $result['weight']['unit']);
    }

    public function testGetSizeAndWeightMappingConvertsLbsToKg(): void
    {
        $product = $this->createProductMock();
        $product->method('getId')->willReturn(1);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getTradeazeSizeCategory')->willReturn('size_medium_box');
        $product->method('getTradeazeWeightCategory')->willReturn(null);
        $product->method('getData')->willReturnCallback(fn($key) => match ($key) {
            'ts_weight' => '22.0',
            default => null
        });

        $this->config->method('getTradeazeLengthAttribute')->willReturn('');
        $this->config->method('getTradeazeWidthAttribute')->willReturn('');
        $this->config->method('getTradeazeHeightAttribute')->willReturn('');
        $this->config->method('getTradeazeWeightAttribute')->willReturn('ts_weight');
        $this->config->method('getTradeazeAttributeDefaultUnit')->willReturn('cm');
        $this->directoryHelper->method('getWeightUnit')->willReturn('lbs');

        $this->sizeCategoryMapping->method('getTradeazeSizeMappingFromCategory')
            ->with('size_medium_box')
            ->willReturn([
                'length' => ['value' => 0.3, 'unit' => 'm'],
                'width' => ['value' => 0.3, 'unit' => 'm'],
                'height' => ['value' => 0.3, 'unit' => 'm'],
            ]);

        $result = $this->service->getSizeAndWeightMapping($product);

        $expectedWeight = 22.0 * 0.45359237;
        $this->assertEqualsWithDelta($expectedWeight, $result['weight']['value'], 0.001);
        $this->assertSame('kg', $result['weight']['unit']);
    }

    public function testGetSizeAndWeightMappingDoesNotConvertCategoryWeight(): void
    {
        $product = $this->createProductMock();
        $product->method('getId')->willReturn(1);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getTradeazeSizeCategory')->willReturn('size_medium_box');
        $product->method('getTradeazeWeightCategory')->willReturn('weight_25');
        $product->method('getData')->willReturn(null);

        $this->config->method('getTradeazeLengthAttribute')->willReturn('');
        $this->config->method('getTradeazeWidthAttribute')->willReturn('');
        $this->config->method('getTradeazeHeightAttribute')->willReturn('');
        $this->config->method('getTradeazeWeightAttribute')->willReturn('');
        $this->config->method('getTradeazeAttributeDefaultUnit')->willReturn('cm');
        $this->directoryHelper->method('getWeightUnit')->willReturn('lbs');

        $this->sizeCategoryMapping->method('getTradeazeSizeMappingFromCategory')
            ->willReturn([
                'length' => ['value' => 0.3, 'unit' => 'm'],
                'width' => ['value' => 0.3, 'unit' => 'm'],
                'height' => ['value' => 0.3, 'unit' => 'm'],
            ]);

        $this->weightCategoryMapping->method('getTradeazeWeightMappingFromCategory')
            ->with('weight_25')
            ->willReturn(['weight' => ['value' => 25, 'unit' => 'kg']]);

        $result = $this->service->getSizeAndWeightMapping($product);

        $this->assertSame(25, $result['weight']['value']);
        $this->assertSame('kg', $result['weight']['unit']);
    }

    public function testGetCountryName(): void
    {
        $country = $this->getMockBuilder(Country::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getName', 'loadByCode'])
            ->getMock();
        $country->method('getName')->willReturn('United Kingdom');
        $country->method('loadByCode')->with('GB')->willReturnSelf();

        $this->countryFactory->method('create')->willReturn($country);

        $this->assertSame('United Kingdom', $this->service->getCountryName('GB'));
    }

    public function testGetPickUpDetailsReturnsSourceData(): void
    {
        $source = $this->createMock(SourceInterface::class);
        $source->method('getStreet')->willReturn('123 Warehouse Road');
        $source->method('getPostcode')->willReturn('SW1A 1AA');
        $source->method('getCity')->willReturn('London');
        $source->method('getCountryId')->willReturn('GB');
        $source->method('getContactName')->willReturn('John');
        $source->method('getPhone')->willReturn('07700900000');

        $country = $this->getMockBuilder(Country::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getName', 'loadByCode'])
            ->getMock();
        $country->method('getName')->willReturn('United Kingdom');
        $country->method('loadByCode')->willReturnSelf();
        $this->countryFactory->method('create')->willReturn($country);

        $result = $this->service->getPickUpDetails($source);

        $this->assertSame('123 Warehouse Road', $result['addressLine1']);
        $this->assertSame('SW1A 1AA', $result['postCode']);
        $this->assertSame('London', $result['city']);
        $this->assertSame('United Kingdom', $result['country']);
        $this->assertSame('John', $result['contactName']);
        $this->assertSame('07700900000', $result['contactNumber']);
        $this->assertSame('PICK_UP', $result['type']);
        $this->assertTrue($result['requiresImage']);
        $this->assertFalse($result['requiresSignature']);
    }

    public function testGetPickUpPostcode(): void
    {
        $source = $this->createMock(SourceInterface::class);
        $source->method('getPostcode')->willReturn('EC1A 1BB');

        $this->assertSame('EC1A 1BB', $this->service->getPickUpPostcode($source));
    }
}
