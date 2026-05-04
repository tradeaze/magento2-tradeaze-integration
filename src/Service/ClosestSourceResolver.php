<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Service;

use Exception;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryDistanceBasedSourceSelection\Model\ResourceModel\GetGeoNamesDataByAddress;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterfaceFactory;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\InventorySourceSelectionApi\Api\Data\AddressInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ClosestSourceResolver
{
    /**
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     * @param StoreManagerInterface $storeManager
     * @param GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesOrderedByPriority
     * @param GetSourceItemsBySkuInterface $getSourceItemsBySku
     * @param GetGeoNamesDataByAddress $getGeoNamesDataByAddress
     * @param AddressInterfaceFactory $addressFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesOrderedByPriority,
        protected readonly GetSourceItemsBySkuInterface $getSourceItemsBySku,
        protected readonly GetGeoNamesDataByAddress $getGeoNamesDataByAddress,
        protected readonly AddressInterfaceFactory $addressFactory,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resolve the closest inventory source that can fulfill all items
     *
     * @param array $items Array of ['sku' => string, 'qty' => float]
     * @param AddressInterface|null $shippingAddress
     * @return SourceInterface|null
     */
    public function resolve(array $items, ?AddressInterface $shippingAddress = null): ?SourceInterface
    {
        try {
            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
            $stock = $this->stockByWebsiteIdResolver->execute($websiteId);
            $sources = $this->getSourcesOrderedByPriority->execute($stock->getStockId());
        } catch (Exception $e) {
            $this->logger->error("ClosestSourceResolver: Failed to resolve stock/sources - {$e->getMessage()}");
            return null;
        }

        if (empty($sources)) {
            return null;
        }

        // Build a map of source_code => SourceItemInterface[] for each SKU
        $sourceStockMap = $this->buildSourceStockMap($items, $sources);

        // Filter to sources that can fulfill ALL items
        $eligibleSources = $this->filterEligibleSources($sources, $items, $sourceStockMap);

        if (empty($eligibleSources)) {
            return null;
        }

        // If only one eligible source or no shipping address, return the first (highest priority)
        if (count($eligibleSources) === 1 || $shippingAddress === null) {
            return reset($eligibleSources);
        }

        return $this->sortByDistance($eligibleSources, $shippingAddress);
    }

    /**
     * Build a map of source availability for each SKU
     *
     * @param array $items
     * @param SourceInterface[] $sources
     * @return array Map of sku => [source_code => SourceItemInterface]
     */
    private function buildSourceStockMap(array $items, array $sources): array
    {
        $validSourceCodes = [];
        foreach ($sources as $source) {
            $validSourceCodes[$source->getSourceCode()] = true;
        }

        $sourceStockMap = [];
        foreach ($items as $item) {
            $sku = $item['sku'];
            $sourceItems = $this->getSourceItemsBySku->execute($sku);
            $sourceStockMap[$sku] = [];
            foreach ($sourceItems as $sourceItem) {
                if (isset($validSourceCodes[$sourceItem->getSourceCode()])) {
                    $sourceStockMap[$sku][$sourceItem->getSourceCode()] = $sourceItem;
                }
            }
        }

        return $sourceStockMap;
    }

    /**
     * Filter sources to only those that can fulfill all items
     *
     * @param SourceInterface[] $sources
     * @param array $items
     * @param array $sourceStockMap
     * @return SourceInterface[]
     */
    private function filterEligibleSources(array $sources, array $items, array $sourceStockMap): array
    {
        $eligible = [];

        foreach ($sources as $source) {
            $sourceCode = $source->getSourceCode();
            $canFulfill = true;

            foreach ($items as $item) {
                $sku = $item['sku'];
                $qty = (float) $item['qty'];

                if (!isset($sourceStockMap[$sku][$sourceCode])) {
                    $canFulfill = false;
                    break;
                }

                $sourceItem = $sourceStockMap[$sku][$sourceCode];

                if ((int) $sourceItem->getStatus() !== SourceItemInterface::STATUS_IN_STOCK
                    || $sourceItem->getQuantity() < $qty
                ) {
                    $canFulfill = false;
                    break;
                }
            }

            if ($canFulfill) {
                $eligible[] = $source;
            }
        }

        return $eligible;
    }

    /**
     * Sort eligible sources by distance to shipping address, return closest
     *
     * Falls back to priority ordering if geocoding fails
     *
     * @param SourceInterface[] $eligibleSources
     * @param AddressInterface $shippingAddress
     * @return SourceInterface
     */
    private function sortByDistance(array $eligibleSources, AddressInterface $shippingAddress): SourceInterface
    {
        try {
            $destLatLng = $this->getLatLngFromGeoNames($shippingAddress);
            $destLat = $destLatLng['latitude'];
            $destLng = $destLatLng['longitude'];
        } catch (Exception $e) {
            $this->logger->info(
                "ClosestSourceResolver: Could not geocode shipping address, falling back to priority order"
                . " - {$e->getMessage()}"
            );
            return reset($eligibleSources);
        }

        $closest = null;
        $closestDistance = PHP_FLOAT_MAX;

        foreach ($eligibleSources as $source) {
            try {
                $sourceLatLng = $this->getLatLngForSource($source);
                $distance = $this->calculateHaversineDistance(
                    $sourceLatLng['latitude'],
                    $sourceLatLng['longitude'],
                    $destLat,
                    $destLng
                );

                if ($distance < $closestDistance) {
                    $closestDistance = $distance;
                    $closest = $source;
                }
            } catch (Exception $e) {
                $this->logger->info(
                    "ClosestSourceResolver: Could not geocode source '{$source->getSourceCode()}'"
                    . " - {$e->getMessage()}"
                );
            }
        }

        // If no source could be geocoded, fall back to priority ordering
        return $closest ?? reset($eligibleSources);
    }

    /**
     * Get lat/lng for a source, using explicit coordinates if set, otherwise GeoNames lookup
     *
     * @param SourceInterface $source
     * @return array{latitude: float, longitude: float}
     * @throws NoSuchEntityException
     */
    private function getLatLngForSource(SourceInterface $source): array
    {
        $lat = (float) $source->getLatitude();
        $lng = (float) $source->getLongitude();

        if ($lat != 0.0 || $lng != 0.0) {
            return [
                'latitude' => $lat,
                'longitude' => $lng
            ];
        }

        $sourceAddress = $this->addressFactory->create([
            'country' => (string) $source->getCountryId(),
            'postcode' => (string) $source->getPostcode(),
            'street' => (string) $source->getStreet(),
            'region' => (string) $source->getRegion(),
            'city' => (string) $source->getCity()
        ]);

        return $this->getLatLngFromGeoNames($sourceAddress);
    }

    /**
     * Resolve lat/lng from the offline GeoNames data (inventory_geoname table)
     *
     * @param AddressInterface $address
     * @return array{latitude: float, longitude: float}
     * @throws NoSuchEntityException
     */
    private function getLatLngFromGeoNames(AddressInterface $address): array
    {
        $outcode = $this->extractOutcode($address->getPostcode());

        $lookupAddress = $this->addressFactory->create([
            'country' => $address->getCountry(),
            'postcode' => $outcode,
            'street' => $address->getStreet(),
            'region' => $address->getRegion(),
            'city' => $address->getCity()
        ]);

        $geoData = $this->getGeoNamesDataByAddress->execute($lookupAddress);
        $firstResult = reset($geoData);

        if (!$firstResult || !isset($firstResult['latitude'], $firstResult['longitude'])) {
            throw new NoSuchEntityException(
                __(
                    "No GeoNames data found for postcode '%1' country '%2'",
                    $address->getPostcode(),
                    $address->getCountry()
                )
            );
        }

        return [
            'latitude' => (float) $firstResult['latitude'],
            'longitude' => (float) $firstResult['longitude']
        ];
    }

    /**
     * Extract the outcode (area + district) from a UK postcode
     *
     * UK postcodes have the format: outcode + incode, where the incode is always
     * the last 3 characters. GeoNames stores UK postcodes as outcodes only.
     * e.g. "N78QZ" → "N7", "SW1A 1AA" → "SW1A", "ST4 2AB" → "ST4"
     *
     * @param string $postcode
     * @return string
     */
    private function extractOutcode(string $postcode): string
    {
        $postcode = strtoupper(preg_replace('/\s+/', '', $postcode));

        if (strlen($postcode) > 3) {
            return substr($postcode, 0, -3);
        }

        return $postcode;
    }

    /**
     * Calculate the Haversine distance between two coordinates in kilometres
     *
     * @param float $lat1
     * @param float $lng1
     * @param float $lat2
     * @param float $lng2
     * @return float
     */
    private function calculateHaversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
