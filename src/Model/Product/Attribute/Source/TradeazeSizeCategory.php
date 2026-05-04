<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Product\Attribute\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Tradeaze\ApiIntegration\Helper\Config;

class TradeazeSizeCategory extends AbstractSource
{
    protected const TRADEAZE_SIZE_CATEGORY_MAPPING = [
        'size_tiny_box' => [
            'label' => 'Tiny Box (~50×50×50mm)',
            'description' => 'Fixings, screws, small fittings, connectors',
            'size_mapping' => [
                'length' => ['value' => 0.05],
                'width'  => ['value' => 0.05],
                'height' => ['value' => 0.05],
            ],
        ],
        'size_small_box' => [
            'label' => 'Small Box (~150×150×150mm)',
            'description' => 'Tubs of adhesive, small hardware, valves, small tins',
            'size_mapping' => [
                'length' => ['value' => 0.15],
                'width'  => ['value' => 0.15],
                'height' => ['value' => 0.15],
            ],
        ],
        'size_medium_box' => [
            'label' => 'Medium Box (~300×300×300mm)',
            'description' => 'Paint tins, tool boxes, light fittings, mixed trade items',
            'size_mapping' => [
                'length' => ['value' => 0.3],
                'width'  => ['value' => 0.3],
                'height' => ['value' => 0.3],
            ],
        ],
        'size_large_box' => [
            'label' => 'Large Box (~500×500×500mm)',
            'description' => 'Boxed taps, bathroom fittings, consumer units, large hardware',
            'size_mapping' => [
                'length' => ['value' => 0.5],
                'width'  => ['value' => 0.5],
                'height' => ['value' => 0.5],
            ],
        ],
        'size_xl_box' => [
            'label' => 'XL Box (~750×750×750mm)',
            'description' => 'Boxed radiators, appliance parts, large packaged goods',
            'size_mapping' => [
                'length' => ['value' => 0.75],
                'width'  => ['value' => 0.75],
                'height' => ['value' => 0.75],
            ],
        ],
        'size_trade_bag' => [
            'label' => 'Trade Bag (~500×350×150mm)',
            'description' => '25kg cement, plaster, sand, tile adhesive, grout bags',
            'size_mapping' => [
                'length' => ['value' => 0.5],
                'width'  => ['value' => 0.35],
                'height' => ['value' => 0.15],
            ],
        ],
        'size_sheet_1200x600' => [
            'label' => 'Sheet 1200×600mm',
            'description' => 'Insulation boards, cement boards, tile backer boards',
            'size_mapping' => [
                'length' => ['value' => 1.2],
                'width'  => ['value' => 0.6],
                'height' => ['value' => 0.022],
            ],
        ],
        'size_sheet_2400x1200' => [
            'label' => 'Sheet 2400×1200mm (8\'×4\')',
            'description' => 'Plywood, plasterboard, MDF, OSB, chipboard',
            'size_mapping' => [
                'length' => ['value' => 2.4],
                'width'  => ['value' => 1.2],
                'height' => ['value' => 0.022],
            ],
        ],
        'size_sheet_3000x1200' => [
            'label' => 'Sheet 3000×1200mm (10\'×4\')',
            'description' => 'Long plasterboard, large sheet materials',
            'size_mapping' => [
                'length' => ['value' => 3.0],
                'width'  => ['value' => 1.2],
                'height' => ['value' => 0.022],
            ],
        ],
        'size_panel_door' => [
            'label' => 'Panel / Door (~2100×900×50mm)',
            'description' => 'Internal/external doors, shower screens, large flat panels',
            'size_mapping' => [
                'length' => ['value' => 2.1],
                'width'  => ['value' => 0.9],
                'height' => ['value' => 0.05],
            ],
        ],
        'size_long_2400' => [
            'label' => 'Long Item 2.4m',
            'description' => 'Short timber, skirting, trunking, short lintels, architrave',
            'size_mapping' => [
                'length' => ['value' => 2.4],
                'width'  => ['value' => 0.1],
                'height' => ['value' => 0.05],
            ],
        ],
        'size_long_3600' => [
            'label' => 'Long Item 3.6m',
            'description' => 'Medium timber, guttering, conduit, medium lintels',
            'size_mapping' => [
                'length' => ['value' => 3.6],
                'width'  => ['value' => 0.1],
                'height' => ['value' => 0.05],
            ],
        ],
        'size_long_6000' => [
            'label' => 'Long Item 6.0m',
            'description' => 'Long timber, steel lintels, cladding lengths',
            'size_mapping' => [
                'length' => ['value' => 6.0],
                'width'  => ['value' => 0.1],
                'height' => ['value' => 0.05],
            ],
        ],
        'size_pipe_3000' => [
            'label' => 'Pipe 3.0m',
            'description' => 'Soil pipe, drainage pipe, rainwater pipe, gutter lengths',
            'size_mapping' => [
                'length' => ['value' => 3.0],
                'width'  => ['value' => 0.11],
                'height' => ['value' => 0.11],
            ],
        ],
        'size_pipe_6000' => [
            'label' => 'Pipe 6.0m',
            'description' => 'Long drainage runs, industrial pipework',
            'size_mapping' => [
                'length' => ['value' => 6.0],
                'width'  => ['value' => 0.11],
                'height' => ['value' => 0.11],
            ],
        ],
        'size_insulation_roll' => [
            'label' => 'Insulation Roll (~1200×500×500mm)',
            'description' => 'Loft rolls, mineral wool, membrane rolls, DPC rolls',
            'size_mapping' => [
                'length' => ['value' => 1.2],
                'width'  => ['value' => 0.5],
                'height' => ['value' => 0.5],
            ],
        ],
        'size_boiler' => [
            'label' => 'Boiler (~800×500×400mm)',
            'description' => 'Combi boilers, system boilers, hot water cylinders',
            'size_mapping' => [
                'length' => ['value' => 0.8],
                'width'  => ['value' => 0.5],
                'height' => ['value' => 0.4],
            ],
        ],
        'size_pallet' => [
            'label' => 'Pallet (1200×1000×1200mm)',
            'description' => 'Palletised bricks, blocks, aggregates, bulk orders',
            'size_mapping' => [
                'length' => ['value' => 1.2],
                'width'  => ['value' => 1.0],
                'height' => ['value' => 1.2],
            ],
        ],
        'size_bulk_bag' => [
            'label' => 'Bulk Bag (~900×900×900mm)',
            'description' => 'Sand, gravel, aggregate, topsoil bulk bags',
            'size_mapping' => [
                'length' => ['value' => 0.9],
                'width'  => ['value' => 0.9],
                'height' => ['value' => 0.9],
            ],
        ],
    ];

    /**
     * @param Config $tradeazeConfig
     */
    public function __construct(
        private readonly Config $tradeazeConfig
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getAllOptions(): array
    {
        $this->_options = [
            ['value' => '', 'label' => __('-- Please Select --')],
        ];
        foreach (self::TRADEAZE_SIZE_CATEGORY_MAPPING as $value => $map) {
            $this->_options[] = ['value' => $value, 'label' => __($map['label'])];
        }

        return $this->_options;
    }

    /**
     * Get Tradeaze Category Size mapping to dimensions with units
     *
     * @param string $category
     * @return array
     */
    public function getTradeazeSizeMappingFromCategory(string $category): array
    {
        $sizeMapping = self::TRADEAZE_SIZE_CATEGORY_MAPPING[$category]['size_mapping'];
        $unit = $this->tradeazeConfig->getTradeazeAttributeDefaultUnit();
        foreach (array_keys($sizeMapping) as $key) {
            $sizeMapping[$key]['unit'] = $unit;
        }

        return $sizeMapping;
    }
}
