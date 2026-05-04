<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Service;

use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryDistanceBasedSourceSelection\Model\ResourceModel\GetGeoNamesDataByAddress;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tradeaze\ApiIntegration\Service\ClosestSourceResolver;

class ClosestSourceResolverTest extends TestCase
{
    private ClosestSourceResolver $resolver;
    private StockByWebsiteIdResolverInterface&MockObject $stockByWebsiteIdResolver;
    private StoreManagerInterface&MockObject $storeManager;
    private GetSourcesAssignedToStockOrderedByPriorityInterface&MockObject $getSourcesOrderedByPriority;
    private GetSourceItemsBySkuInterface&MockObject $getSourceItemsBySku;
    private GetGeoNamesDataByAddress&MockObject $getGeoNamesDataByAddress;
    private AddressInterfaceFactory&MockObject $addressFactory;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->stockByWebsiteIdResolver = $this->createMock(StockByWebsiteIdResolverInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->getSourcesOrderedByPriority = $this->createMock(GetSourcesAssignedToStockOrderedByPriorityInterface::class);
        $this->getSourceItemsBySku = $this->createMock(GetSourceItemsBySkuInterface::class);
        $this->getGeoNamesDataByAddress = $this->createMock(GetGeoNamesDataByAddress::class);
        $this->addressFactory = $this->createMock(AddressInterfaceFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resolver = new ClosestSourceResolver(
            $this->stockByWebsiteIdResolver,
            $this->storeManager,
            $this->getSourcesOrderedByPriority,
            $this->getSourceItemsBySku,
            $this->getGeoNamesDataByAddress,
            $this->addressFactory,
            $this->logger
        );
    }

    private function setupStockResolution(array $sources): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $this->storeManager->method('getStore')->willReturn($store);

        $stock = $this->createMock(\Magento\InventoryApi\Api\Data\StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $this->stockByWebsiteIdResolver->method('execute')->with(1)->willReturn($stock);
        $this->getSourcesOrderedByPriority->method('execute')->with(1)->willReturn($sources);
    }

    private function createSource(string $code, bool $enabled = true): SourceInterface&MockObject
    {
        $source = $this->createMock(SourceInterface::class);
        $source->method('getSourceCode')->willReturn($code);
        $source->method('isEnabled')->willReturn($enabled);
        return $source;
    }

    private function createSourceItem(string $sourceCode, string $sku, float $qty, bool $inStock = true): SourceItemInterface&MockObject
    {
        $item = $this->createMock(SourceItemInterface::class);
        $item->method('getSourceCode')->willReturn($sourceCode);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQuantity')->willReturn($qty);
        $item->method('getStatus')->willReturn($inStock ? SourceItemInterface::STATUS_IN_STOCK : SourceItemInterface::STATUS_OUT_OF_STOCK);
        return $item;
    }

    public function testResolveReturnsSingleEligibleSource(): void
    {
        $source = $this->createSource('warehouse_a');
        $this->setupStockResolution([$source]);

        $sourceItem = $this->createSourceItem('warehouse_a', 'SKU-001', 10.0);
        $this->getSourceItemsBySku->method('execute')
            ->with('SKU-001')
            ->willReturn([$sourceItem]);

        $items = [['sku' => 'SKU-001', 'qty' => 2.0]];
        $result = $this->resolver->resolve($items);

        $this->assertSame($source, $result);
    }

    public function testResolveReturnsNullWhenNoSourceHasStock(): void
    {
        $source = $this->createSource('warehouse_a');
        $this->setupStockResolution([$source]);

        $sourceItem = $this->createSourceItem('warehouse_a', 'SKU-001', 1.0);
        $this->getSourceItemsBySku->method('execute')
            ->with('SKU-001')
            ->willReturn([$sourceItem]);

        $items = [['sku' => 'SKU-001', 'qty' => 5.0]];
        $result = $this->resolver->resolve($items);

        $this->assertNull($result);
    }

    public function testResolveReturnsNullWhenSourceOutOfStock(): void
    {
        $source = $this->createSource('warehouse_a');
        $this->setupStockResolution([$source]);

        $sourceItem = $this->createSourceItem('warehouse_a', 'SKU-001', 10.0, false);
        $this->getSourceItemsBySku->method('execute')
            ->with('SKU-001')
            ->willReturn([$sourceItem]);

        $items = [['sku' => 'SKU-001', 'qty' => 1.0]];
        $result = $this->resolver->resolve($items);

        $this->assertNull($result);
    }

    public function testResolveReturnsSourceThatHasAllItems(): void
    {
        $sourceA = $this->createSource('warehouse_a');
        $sourceB = $this->createSource('warehouse_b');
        $this->setupStockResolution([$sourceA, $sourceB]);

        // Source A has SKU-001 but not SKU-002
        // Source B has both
        $this->getSourceItemsBySku->method('execute')
            ->willReturnCallback(fn($sku) => match ($sku) {
                'SKU-001' => [
                    $this->createSourceItem('warehouse_a', 'SKU-001', 10.0),
                    $this->createSourceItem('warehouse_b', 'SKU-001', 5.0),
                ],
                'SKU-002' => [
                    $this->createSourceItem('warehouse_b', 'SKU-002', 8.0),
                ],
                default => []
            });

        $items = [
            ['sku' => 'SKU-001', 'qty' => 1.0],
            ['sku' => 'SKU-002', 'qty' => 1.0],
        ];

        $result = $this->resolver->resolve($items);

        $this->assertSame($sourceB, $result);
    }

    public function testResolveReturnsNullWhenNoSingleSourceCanFulfillAll(): void
    {
        $sourceA = $this->createSource('warehouse_a');
        $sourceB = $this->createSource('warehouse_b');
        $this->setupStockResolution([$sourceA, $sourceB]);

        // Source A has SKU-001 only, Source B has SKU-002 only
        $this->getSourceItemsBySku->method('execute')
            ->willReturnCallback(fn($sku) => match ($sku) {
                'SKU-001' => [$this->createSourceItem('warehouse_a', 'SKU-001', 10.0)],
                'SKU-002' => [$this->createSourceItem('warehouse_b', 'SKU-002', 8.0)],
                default => []
            });

        $items = [
            ['sku' => 'SKU-001', 'qty' => 1.0],
            ['sku' => 'SKU-002', 'qty' => 1.0],
        ];

        $result = $this->resolver->resolve($items);

        $this->assertNull($result);
    }

    public function testResolveReturnsNullWhenNoSources(): void
    {
        $this->setupStockResolution([]);

        $items = [['sku' => 'SKU-001', 'qty' => 1.0]];
        $result = $this->resolver->resolve($items);

        $this->assertNull($result);
    }

    public function testResolveFallsBackToPriorityWhenGeocodingFails(): void
    {
        $sourceA = $this->createSource('warehouse_a');
        $sourceB = $this->createSource('warehouse_b');
        $this->setupStockResolution([$sourceA, $sourceB]);

        // Both sources have stock
        $this->getSourceItemsBySku->method('execute')
            ->willReturn([
                $this->createSourceItem('warehouse_a', 'SKU-001', 10.0),
                $this->createSourceItem('warehouse_b', 'SKU-001', 10.0),
            ]);

        $address = $this->createMock(AddressInterface::class);
        $address->method('getPostcode')->willReturn('SW1A 1AA');
        $address->method('getCountry')->willReturn('GB');
        $address->method('getStreet')->willReturn('');
        $address->method('getRegion')->willReturn('');
        $address->method('getCity')->willReturn('');

        // Configure addressFactory to return a valid mock so PHP type checking passes
        $lookupAddress = $this->createMock(AddressInterface::class);
        $this->addressFactory->method('create')->willReturn($lookupAddress);

        // Geocoding throws exception
        $this->getGeoNamesDataByAddress->method('execute')
            ->willThrowException(new \Exception('GeoNames data not available'));

        $items = [['sku' => 'SKU-001', 'qty' => 1.0]];
        $result = $this->resolver->resolve($items, $address);

        // Should fall back to priority order (warehouse_a is first)
        $this->assertSame($sourceA, $result);
    }
}
