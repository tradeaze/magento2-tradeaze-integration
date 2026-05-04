<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Config\Source;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Model\Product\Attribute\Repository;
use Magento\Eav\Api\Data\AttributeInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\InputException;
use Psr\Log\LoggerInterface;

abstract class TradeazeAttributeAbstract implements OptionSourceInterface
{
    /** @var array */
    protected array $allProductAttributes = [];

    /**
     * @param Repository $attributeRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param SortOrderBuilder $sortOrderBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Repository $attributeRepository,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $optionArray = [
            ['value' => '', 'label' => __('-- Please Select --')],
        ];

        foreach ($this->getAllProductAttributes() as $attribute) {
            $optionArray[] = ['value' => $attribute->getAttributeCode(), 'label' => $attribute->getFrontendLabel()];
        }

        return $optionArray;
    }

    /**
     * Get all product attributes
     *
     * @return array|ProductAttributeInterface[]|AttributeInterface[]
     */
    protected function getAllProductAttributes(): array
    {
        if (!$this->allProductAttributes) {
            try {
                $sortOrder = $this->sortOrderBuilder
                    ->setField('frontend_label')
                    ->setDirection('ASC')
                    ->create();

                $searchCriteria = $this->searchCriteriaBuilderFactory
                    ->create()
                    ->addSortOrder($sortOrder)
                    ->create();

                $this->allProductAttributes = $this->attributeRepository->getList($searchCriteria)->getItems();
            } catch (InputException $e) {
                $this->logger->error($e->getMessage());
            }
        }

        return $this->allProductAttributes;
    }
}
