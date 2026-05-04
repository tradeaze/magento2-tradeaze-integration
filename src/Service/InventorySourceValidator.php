<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Service;

use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterface;

class InventorySourceValidator
{
    /**
     * @param ClosestSourceResolver $closestSourceResolver
     */
    public function __construct(
        protected readonly ClosestSourceResolver $closestSourceResolver
    ) {
    }

    /**
     * Resolve the closest inventory source that can fulfill all items from a single location
     *
     * @param array $items Array of ['sku' => string, 'qty' => float]
     * @param AddressInterface|null $shippingAddress
     * @return SourceInterface|null
     */
    public function resolveSource(array $items, ?AddressInterface $shippingAddress = null): ?SourceInterface
    {
        return $this->closestSourceResolver->resolve($items, $shippingAddress);
    }

    /**
     * True if all items can be fulfilled from a single inventory source, false if not
     *
     * @param array $items Array of ['sku' => string, 'qty' => float]
     * @param AddressInterface|null $shippingAddress
     * @return bool
     */
    public function validate(array $items, ?AddressInterface $shippingAddress = null): bool
    {
        return $this->resolveSource($items, $shippingAddress) !== null;
    }
}
