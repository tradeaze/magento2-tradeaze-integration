<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class TradeazeDefaultUnit implements OptionSourceInterface
{

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'm', 'label' => __('m')],
            ['value' => 'cm', 'label' => __('cm')],
            ['value' => 'mm', 'label' => __('mm')]
        ];
    }
}
