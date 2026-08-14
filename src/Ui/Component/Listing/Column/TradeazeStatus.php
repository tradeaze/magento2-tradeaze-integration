<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Ui\Component\Listing\Column;

use Magento\Framework\Data\OptionSourceInterface;
use Tradeaze\ApiIntegration\Service\Tradeaze;

class TradeazeStatus implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $statuses = [
            [
                'value' => Tradeaze::AWAITING_PROCESSING_STATUS,
                'label' => __('AWAITING PROCESSING'),
            ],
            ['value' => Tradeaze::NOT_REQUIRED_STATUS, 'label' => __('NOT REQUIRED')],
            ['value' => Tradeaze::PENDING_STATUS, 'label' => __('PENDING')],
            ['value' => 'CONFIRMED', 'label' => __('CONFIRMED')],
            ['value' => 'DELIVERED', 'label' => __('DELIVERED')],
            ['value' => 'REJECTED', 'label' => __('REJECTED')],
            ['value' => 'CANCELLED', 'label' => __('CANCELLED')],
        ];

        $failedSyncIndex = 1;
        while ($failedSyncIndex <= Tradeaze::MAX_NUMBER_OF_REATTEMPTS) {
            $failedSyncStatus = Tradeaze::ORDER_STATUS_PATTERN_TO_RETRY . $failedSyncIndex;
            $statuses[] = ['value' => $failedSyncStatus, 'label' => __($failedSyncStatus)];
            $failedSyncIndex++;
        }

        $statuses[] = ['value' => Tradeaze::FAILED_STATUS, 'label' => Tradeaze::FAILED_STATUS];
        return $statuses;
    }
}
