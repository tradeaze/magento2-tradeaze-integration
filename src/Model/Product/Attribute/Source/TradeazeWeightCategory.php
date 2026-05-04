<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Product\Attribute\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class TradeazeWeightCategory extends AbstractSource
{
    public const TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT = 'kg';

    protected const TRADEAZE_WEIGHT_CATEGORY_MAPPING = [
        'weight_1' => [
            'label' => '≤1 kg',
            'size_mapping' => [
                'weight' => ['value' => 1, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
        'weight_5' => [
            'label' => '≤5 kg',
            'size_mapping' => [
                'weight' => ['value' => 5, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
        'weight_10' => [
            'label' => '≤10 kg',
            'size_mapping' => [
                'weight' => ['value' => 10, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
        'weight_25' => [
            'label' => '≤25 kg',
            'size_mapping' => [
                'weight' => ['value' => 25, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
        'weight_50' => [
            'label' => '≤50 kg',
            'size_mapping' => [
                'weight' => ['value' => 50, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
        'weight_100' => [
            'label' => '≤100 kg',
            'size_mapping' => [
                'weight' => ['value' => 100, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
        'weight_250' => [
            'label' => '≤250 kg',
            'size_mapping' => [
                'weight' => ['value' => 250, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
        'weight_500' => [
            'label' => '≤500 kg',
            'size_mapping' => [
                'weight' => ['value' => 500, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
        'weight_750' => [
            'label' => '≤750 kg',
            'size_mapping' => [
                'weight' => ['value' => 750, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
        'weight_1000' => [
            'label' => '≤1000 kg',
            'size_mapping' => [
                'weight' => ['value' => 1000, 'unit' => self::TRADEAZE_WEIGHT_ATTRIBUTE_DEFAULT_UNIT],
            ],
        ],
    ];

    /**
     * @inheritdoc
     */
    public function getAllOptions(): array
    {
        $this->_options = [
            ['value' => '', 'label' => __('-- Please Select --')],
        ];
        foreach (self::TRADEAZE_WEIGHT_CATEGORY_MAPPING as $value => $map) {
            $this->_options[] = ['value' => $value, 'label' => __($map['label'])];
        }

        return $this->_options;
    }

    /**
     * Get Tradeaze Category Weight mapping to dimensions with units
     *
     * @param string $category
     * @return array
     */
    public function getTradeazeWeightMappingFromCategory(string $category): array
    {
        return self::TRADEAZE_WEIGHT_CATEGORY_MAPPING[$category]['size_mapping'];
    }
}
