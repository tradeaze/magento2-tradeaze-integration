<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class TradeazeApiMode implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'test', 'label' => __('Test (Staging)')],
            ['value' => 'live', 'label' => __('Live (Production)')],
        ];
    }
}
