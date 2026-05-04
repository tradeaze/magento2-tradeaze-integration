<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Api\Data;

interface DataInterface
{
    /**
     * Get created at timestamp
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string;

    /**
     * Get updated at timestamp
     *
     * @return string|null
     */
    public function getUpdatedAt(): ?string;

    /**
     * Get Tradeaze order ID
     *
     * @return string|null
     */
    public function getId(): ?string;

    /**
     * Get external (Magento) order increment ID
     *
     * @return string|null
     */
    public function getExternalId(): ?string;

    /**
     * Get tracking URL
     *
     * @return string|null
     */
    public function getTrackingUrl(): ?string;

    /**
     * Get order status
     *
     * @return string|null
     */
    public function getOrderStatus(): ?string;

    /**
     * Get delivery status
     *
     * @return string|null
     */
    public function getDeliveryStatus(): ?string;

    /**
     * Get delivery option ID
     *
     * @return string|null
     */
    public function getDeliveryOptionId(): ?string;

    /**
     * Get whether enclosed vehicles only
     *
     * @return bool|null
     */
    public function getEnclosedVehiclesOnly(): ?bool;

    /**
     * Get delivery price
     *
     * @return mixed
     */
    public function getDeliveryPrice(): mixed;

    /**
     * Get service charge
     *
     * @return mixed
     */
    public function getServiceCharge(): mixed;

    /**
     * Get tips
     *
     * @return mixed
     */
    public function getTips(): mixed;

    /**
     * Get pickup data
     *
     * @return mixed
     */
    public function getPickup(): mixed;

    /**
     * Get dropoff data
     *
     * @return mixed
     */
    public function getDropoff(): mixed;

    /**
     * Get items data
     *
     * @return mixed
     */
    public function getItems(): mixed;

    /**
     * Get courier data
     *
     * @return mixed
     */
    public function getCourier(): mixed;

    /**
     * Get metadata
     *
     * @return mixed
     */
    public function getMetadata(): mixed;

    /**
     * Set created at timestamp
     *
     * @param string|null $createdAt
     * @return self
     */
    public function setCreatedAt(?string $createdAt): self;

    /**
     * Set updated at timestamp
     *
     * @param string|null $updatedAt
     * @return self
     */
    public function setUpdatedAt(?string $updatedAt): self;

    /**
     * Set Tradeaze order ID
     *
     * @param string|null $id
     * @return self
     */
    public function setId(?string $id): self;

    /**
     * Set external (Magento) order increment ID
     *
     * @param string|null $externalId
     * @return self
     */
    public function setExternalId(?string $externalId): self;

    /**
     * Set tracking URL
     *
     * @param string|null $trackingUrl
     * @return self
     */
    public function setTrackingUrl(?string $trackingUrl): self;

    /**
     * Set order status
     *
     * @param string|null $orderStatus
     * @return self
     */
    public function setOrderStatus(?string $orderStatus): self;

    /**
     * Set delivery status
     *
     * @param string|null $deliveryStatus
     * @return self
     */
    public function setDeliveryStatus(?string $deliveryStatus): self;

    /**
     * Set delivery option ID
     *
     * @param string|null $deliveryOptionId
     * @return self
     */
    public function setDeliveryOptionId(?string $deliveryOptionId): self;

    /**
     * Set whether enclosed vehicles only
     *
     * @param bool|null $enclosedVehiclesOnly
     * @return self
     */
    public function setEnclosedVehiclesOnly(?bool $enclosedVehiclesOnly): self;

    /**
     * Set delivery price
     *
     * @param mixed $deliveryPrice
     * @return self
     */
    public function setDeliveryPrice(mixed $deliveryPrice): self;

    /**
     * Set service charge
     *
     * @param mixed $serviceCharge
     * @return self
     */
    public function setServiceCharge(mixed $serviceCharge): self;

    /**
     * Set tips
     *
     * @param mixed $tips
     * @return self
     */
    public function setTips(mixed $tips): self;

    /**
     * Set pickup data
     *
     * @param mixed $pickup
     * @return self
     */
    public function setPickup(mixed $pickup): self;

    /**
     * Set dropoff data
     *
     * @param mixed $dropoff
     * @return self
     */
    public function setDropoff(mixed $dropoff): self;

    /**
     * Set items data
     *
     * @param mixed $items
     * @return self
     */
    public function setItems(mixed $items): self;

    /**
     * Set courier data
     *
     * @param mixed $courier
     * @return self
     */
    public function setCourier(mixed $courier): self;

    /**
     * Set metadata
     *
     * @param mixed $metadata
     * @return self
     */
    public function setMetadata(mixed $metadata): self;
}
