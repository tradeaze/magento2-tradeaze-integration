<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Model\Api\Data;

class DataTest extends TestCase
{
    private Data $data;

    protected function setUp(): void
    {
        $this->data = new Data();
    }

    public function testGettersReturnNullByDefault(): void
    {
        $this->assertNull($this->data->getId());
        $this->assertNull($this->data->getExternalId());
        $this->assertNull($this->data->getOrderStatus());
        $this->assertNull($this->data->getCreatedAt());
        $this->assertNull($this->data->getUpdatedAt());
        $this->assertNull($this->data->getTrackingUrl());
        $this->assertNull($this->data->getDeliveryStatus());
        $this->assertNull($this->data->getDeliveryOptionId());
        $this->assertNull($this->data->getEnclosedVehiclesOnly());
    }

    public function testSettersReturnSelfForChaining(): void
    {
        $result = $this->data
            ->setId('order-123')
            ->setExternalId('000000001')
            ->setOrderStatus('CONFIRMED');

        $this->assertSame($this->data, $result);
    }

    public function testSetAndGetId(): void
    {
        $this->data->setId('trz-order-456');
        $this->assertSame('trz-order-456', $this->data->getId());
    }

    public function testSetAndGetExternalId(): void
    {
        $this->data->setExternalId('000000099');
        $this->assertSame('000000099', $this->data->getExternalId());
    }

    public function testSetAndGetOrderStatus(): void
    {
        $this->data->setOrderStatus('DELIVERED');
        $this->assertSame('DELIVERED', $this->data->getOrderStatus());
    }

    public function testSetAndGetDeliveryPrice(): void
    {
        $this->data->setDeliveryPrice(12.50);
        $this->assertSame(12.50, $this->data->getDeliveryPrice());
    }

    public function testSetAndGetPickup(): void
    {
        $pickup = ['postcode' => 'SW1A 1AA', 'city' => 'London'];
        $this->data->setPickup($pickup);
        $this->assertSame($pickup, $this->data->getPickup());
    }

    public function testSetAndGetItems(): void
    {
        $items = [['sku' => 'ABC', 'qty' => 2]];
        $this->data->setItems($items);
        $this->assertSame($items, $this->data->getItems());
    }
}
