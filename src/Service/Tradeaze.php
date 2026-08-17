<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Service;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Inventory\Model\Source;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventorySalesApi\Model\GetAssignedStockIdForWebsiteInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Tradeaze\ApiIntegration\Helper\Config;
use Tradeaze\ApiIntegration\Model\Product\Attribute\Source\TradeazeSizeCategory;
use Tradeaze\ApiIntegration\Model\Product\Attribute\Source\TradeazeWeightCategory;

class Tradeaze
{
    public const MAX_NUMBER_OF_REATTEMPTS = 4;
    public const ORDER_STATUS_PATTERN_TO_RETRY = 'FAILEDSYNC';
    public const FAILED_STATUS = 'FAILED';

    /**
     * Parked until the payment is captured - no delivery has been attempted yet
     *
     * Kept distinct from ORDER_STATUS_PATTERN_TO_RETRY so that an order still waiting for its
     * payment provider is not indistinguishable, in the sales grid, from one whose delivery
     * call actually failed. Unpaid and abandoned checkouts sit in this status indefinitely.
     */
    public const AWAITING_PAYMENT_STATUS = 'AWAITINGPAYMENT';

    /**
     * @param Config $config
     * @param TradeazeSizeCategory $sizeCategoryMapping
     * @param TradeazeWeightCategory $weightCategoryMapping
     * @param CountryFactory $countryFactory
     * @param GetAssignedStockIdForWebsiteInterface $getAssignedStockIdForWebsite
     * @param StoreManagerInterface $storeManager
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     * @param GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesOrderedByPriority
     * @param ProductResource $productResource
     * @param DirectoryHelper $directoryHelper
     */
    public function __construct(
        protected readonly Config $config,
        protected readonly TradeazeSizeCategory $sizeCategoryMapping,
        protected readonly TradeazeWeightCategory $weightCategoryMapping,
        protected readonly CountryFactory $countryFactory,
        protected readonly GetAssignedStockIdForWebsiteInterface $getAssignedStockIdForWebsite,
        protected readonly StoreManagerInterface $storeManager,
        protected readonly StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        protected readonly GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesOrderedByPriority,
        protected readonly ProductResource $productResource,
        protected readonly DirectoryHelper $directoryHelper,
    ) {
    }

    /**
     * Check if a product is eligible for Tradeaze shipping
     *
     * @param Product $product
     * @return bool
     */
    public function isTradeazeProduct(Product $product): bool
    {
        if (!$product->getTradeazeEnabled()) {
            return false;
        }

        $sizeAndWeight = $this->getSizeAndWeightMapping($product);

        return isset($sizeAndWeight['length'])
            && isset($sizeAndWeight['width'])
            && isset($sizeAndWeight['height'])
            && isset($sizeAndWeight['weight']);
    }

    /**
     * Get the size and weight mapping for a Tradeaze product.
     *
     * Plug in here if you need to implement custom length/width/height/weight calculations.
     * Return in below format:
     * $result = [
     *     "length" => ["value" => 10, "unit" => "cm"],
     *     "width" => ["value" => 10, "unit" => "cm"],
     *     "height" => ["value" => 10, "unit" => "cm"],
     *     "weight" => ["value" => 10, "unit" => "kg"]
     * ]
     *
     * Tradeaze API accepts:
     *     length/width/height in mm, cm and m
     *     weight in kg
     *
     * @param Product $product
     * @return array
     * @throws LocalizedException
     */
    public function getSizeAndWeightMapping(Product $product): array
    {
        $result = [];
        $dimensionKeys = ['length', 'width', 'height'];
        $defaultDimensionUnit = $this->config->getTradeazeAttributeDefaultUnit();
        $defaultWeightUnit = TradeazeWeightCategory::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT;

        // Attempt to get dimensions from configured product attributes
        $dimensionAttributes = $this->getDimensionAttributes();
        $hasDimensionsFromAttributes = false;

        if ($dimensionAttributes) {
            $hasDimensionsFromAttributes = true;
            foreach ($dimensionAttributes as $key => $attributeCode) {
                $rawValue = $this->getProductAttributeValue($product, $attributeCode);
                if ($rawValue !== null && $rawValue !== '' && $rawValue !== false && (float) $rawValue !== 0.0) {
                    $result[$key] = ['value' => (float) $rawValue, 'unit' => $defaultDimensionUnit];
                } else {
                    $hasDimensionsFromAttributes = false;
                }
            }
        }

        // If dimension attributes aren't all configured or any value is missing, use size category
        if (!$hasDimensionsFromAttributes && $product->getTradeazeSizeCategory()) {
            $sizeMapping = $this->sizeCategoryMapping
                ->getTradeazeSizeMappingFromCategory($product->getTradeazeSizeCategory());
            foreach ($dimensionKeys as $key) {
                if (isset($sizeMapping[$key])) {
                    $result[$key] = $sizeMapping[$key];
                }
            }
        }

        // Attempt to get weight from configured product attribute
        $weightAttribute = $this->config->getTradeazeWeightAttribute();

        if ($weightAttribute) {
            $rawWeight = $this->getProductAttributeValue($product, $weightAttribute);
            if ($rawWeight !== null && $rawWeight !== '' && $rawWeight !== false && (float) $rawWeight !== 0.0) {
                $weight = (float) $rawWeight;
                // Tradeaze API only accepts weight in kg, convert if Magento is configured for lbs
                if ($this->directoryHelper->getWeightUnit() === 'lbs') {
                    $weight = $weight * 0.45359237;
                }
                $result['weight'] = ['value' => $weight, 'unit' => $defaultWeightUnit];
            }
        }

        // If weight attribute isn't configured or has no value, use weight category
        if (!isset($result['weight']) && $product->getTradeazeWeightCategory()) {
            $weightMapping = $this->weightCategoryMapping
                ->getTradeazeWeightMappingFromCategory($product->getTradeazeWeightCategory());
            if (isset($weightMapping['weight'])) {
                $result['weight'] = $weightMapping['weight'];
            }
        }

        return $result;
    }

    /**
     * Get configured dimension attribute codes if all three are set
     *
     * @return array|null
     */
    private function getDimensionAttributes(): ?array
    {
        $attributes = [
            'length' => $this->config->getTradeazeLengthAttribute(),
            'width' => $this->config->getTradeazeWidthAttribute(),
            'height' => $this->config->getTradeazeHeightAttribute(),
        ];

        return (count(array_filter($attributes)) === count($attributes)) ? $attributes : null;
    }

    /**
     * Get a product attribute value, falling back to a direct DB lookup if not loaded
     *
     * @param Product $product
     * @param string $attributeCode
     * @return mixed
     */
    private function getProductAttributeValue(Product $product, string $attributeCode): mixed
    {
        $value = $product->getData($attributeCode);

        if ($value === null || $value === '') {
            $value = $this->productResource->getAttributeRawValue(
                $product->getId(),
                $attributeCode,
                $product->getStoreId()
            );
        }

        return $value;
    }

    /**
     * Get full country name from country code
     *
     * @param string $countryCode
     * @return string
     */
    public function getCountryName(string $countryCode): string
    {
        return $this->countryFactory->create()
            ->loadByCode($countryCode)
            ->getName();
    }

    /**
     * Get pick-up details for an order
     *
     * @param SourceInterface|null $source
     * @return array
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getPickUpDetails(?SourceInterface $source = null): array
    {
        $source = $source ?? $this->getDefaultSource();

        return [
            'addressLine1'      => (string) $source->getStreet(),
            'addressLine2'      => null,
            'addressLine3'      => null,
            'postCode'          => (string) $source->getPostcode(),
            'city'              => (string) $source->getCity(),
            'country'           => $this->getCountryName((string) $source->getCountryId()),
            'contactName'       => (string) $source->getContactName(),
            'contactNumber'     => (string) $source->getPhone(),
            'companyName'       => null,
            'collectionReference'=> null,
            'requiresSignature' => false,
            'requiresImage'     => true,
            'type'              => 'PICK_UP'
        ];
    }

    /**
     * Get pick-up postcode for an order
     *
     * @param SourceInterface|null $source
     * @return string
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getPickUpPostcode(?SourceInterface $source = null): string
    {
        $source = $source ?? $this->getDefaultSource();
        return $source->getPostcode();
    }

    /**
     * Get Default Source
     *
     * @return Source
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getDefaultSource(): Source
    {
        $stock = $this->stockByWebsiteIdResolver->execute($this->storeManager->getStore()->getWebsiteId());
        $sources = $this->getSourcesOrderedByPriority->execute($stock->getStockId());

        /** @var Source $defaultSource */
        $defaultSource = reset($sources);
        return $defaultSource;
    }
}
