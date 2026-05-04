<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Api;

use Tradeaze\ApiIntegration\Api\Data\DataInterface;

class Data implements DataInterface
{
    /**
     * @var string|null
     */
    private ?string $createdAt = null;

    /**
     * @var string|null
     */
    private ?string $updatedAt = null;

    /**
     * @var string|null
     */
    private ?string $id = null;

    /**
     * @var string|null
     */
    private ?string $externalId = null;

    /**
     * @var string|null
     */
    private ?string $trackingUrl = null;

    /**
     * @var string|null
     */
    private ?string $orderStatus = null;

    /**
     * @var string|null
     */
    private ?string $deliveryStatus = null;

    /**
     * @var string|null
     */
    private ?string $deliveryOptionId = null;

    /**
     * @var bool|null
     */
    private ?bool $enclosedVehiclesOnly = null;

    /**
     * @var mixed
     */
    private mixed $deliveryPrice = null;

    /**
     * @var mixed
     */
    private mixed $serviceCharge = null;

    /**
     * @var mixed
     */
    private mixed $tips = null;

    /**
     * @var mixed
     */
    private mixed $pickup = null;

    /**
     * @var mixed
     */
    private mixed $dropoff = null;

    /**
     * @var mixed
     */
    private mixed $items = null;

    /**
     * @var mixed
     */
    private mixed $courier = null;

    /**
     * @var mixed
     */
    private mixed $metadata = null;

    /**
     * @inheritdoc
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * @inheritdoc
     */
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @inheritdoc
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * @inheritdoc
     */
    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    /**
     * @inheritdoc
     */
    public function getTrackingUrl(): ?string
    {
        return $this->trackingUrl;
    }

    /**
     * @inheritdoc
     */
    public function getOrderStatus(): ?string
    {
        return $this->orderStatus;
    }

    /**
     * @inheritdoc
     */
    public function getDeliveryStatus(): ?string
    {
        return $this->deliveryStatus;
    }

    /**
     * @inheritdoc
     */
    public function getDeliveryOptionId(): ?string
    {
        return $this->deliveryOptionId;
    }

    /**
     * @inheritdoc
     */
    public function getEnclosedVehiclesOnly(): ?bool
    {
        return $this->enclosedVehiclesOnly;
    }

    /**
     * @inheritdoc
     */
    public function getDeliveryPrice(): mixed
    {
        return $this->deliveryPrice;
    }

    /**
     * @inheritdoc
     */
    public function getServiceCharge(): mixed
    {
        return $this->serviceCharge;
    }

    /**
     * @inheritdoc
     */
    public function getTips(): mixed
    {
        return $this->tips;
    }

    /**
     * @inheritdoc
     */
    public function getPickup(): mixed
    {
        return $this->pickup;
    }

    /**
     * @inheritdoc
     */
    public function getDropoff(): mixed
    {
        return $this->dropoff;
    }

    /**
     * @inheritdoc
     */
    public function getItems(): mixed
    {
        return $this->items;
    }

    /**
     * @inheritdoc
     */
    public function getCourier(): mixed
    {
        return $this->courier;
    }

    /**
     * @inheritdoc
     */
    public function getMetadata(): mixed
    {
        return $this->metadata;
    }

    /**
     * @inheritdoc
     */
    public function setCreatedAt(?string $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setUpdatedAt(?string $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setTrackingUrl(?string $trackingUrl): self
    {
        $this->trackingUrl = $trackingUrl;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setOrderStatus(?string $orderStatus): self
    {
        $this->orderStatus = $orderStatus;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setDeliveryStatus(?string $deliveryStatus): self
    {
        $this->deliveryStatus = $deliveryStatus;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setDeliveryOptionId(?string $deliveryOptionId): self
    {
        $this->deliveryOptionId = $deliveryOptionId;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setEnclosedVehiclesOnly(?bool $enclosedVehiclesOnly): self
    {
        $this->enclosedVehiclesOnly = $enclosedVehiclesOnly;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setDeliveryPrice(mixed $deliveryPrice): self
    {
        $this->deliveryPrice = $deliveryPrice;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setServiceCharge(mixed $serviceCharge): self
    {
        $this->serviceCharge = $serviceCharge;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setTips(mixed $tips): self
    {
        $this->tips = $tips;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setPickup(mixed $pickup): self
    {
        $this->pickup = $pickup;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setDropoff(mixed $dropoff): self
    {
        $this->dropoff = $dropoff;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setItems(mixed $items): self
    {
        $this->items = $items;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setCourier(mixed $courier): self
    {
        $this->courier = $courier;
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setMetadata(mixed $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }
}
