<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Service;

use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Service\ClosestSourceResolver;
use Tradeaze\ApiIntegration\Service\InventorySourceValidator;

class InventorySourceValidatorTest extends TestCase
{
    private InventorySourceValidator $validator;
    private ClosestSourceResolver&MockObject $closestSourceResolver;

    protected function setUp(): void
    {
        $this->closestSourceResolver = $this->createMock(ClosestSourceResolver::class);
        $this->validator = new InventorySourceValidator($this->closestSourceResolver);
    }

    public function testValidateReturnsTrueWhenSourceResolved(): void
    {
        $source = $this->createMock(SourceInterface::class);
        $items = [['sku' => 'SKU-001', 'qty' => 1.0]];

        $this->closestSourceResolver->method('resolve')
            ->with($items, null)
            ->willReturn($source);

        $this->assertTrue($this->validator->validate($items));
    }

    public function testValidateReturnsFalseWhenNoSourceResolved(): void
    {
        $items = [['sku' => 'SKU-001', 'qty' => 1.0]];

        $this->closestSourceResolver->method('resolve')
            ->with($items, null)
            ->willReturn(null);

        $this->assertFalse($this->validator->validate($items));
    }

    public function testResolveSourceDelegatesToClosestSourceResolver(): void
    {
        $source = $this->createMock(SourceInterface::class);
        $address = $this->createMock(AddressInterface::class);
        $items = [['sku' => 'SKU-001', 'qty' => 2.0]];

        $this->closestSourceResolver->method('resolve')
            ->with($items, $address)
            ->willReturn($source);

        $this->assertSame($source, $this->validator->resolveSource($items, $address));
    }

    public function testResolveSourceReturnsNullWhenNoEligibleSource(): void
    {
        $items = [['sku' => 'SKU-001', 'qty' => 100.0]];

        $this->closestSourceResolver->method('resolve')
            ->willReturn(null);

        $this->assertNull($this->validator->resolveSource($items));
    }
}
