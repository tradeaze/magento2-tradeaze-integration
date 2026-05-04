<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Observer\Adminhtml;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Tradeaze\ApiIntegration\Model\Cache\Tradeaze as TradeazeCache;

class ConfigChange implements ObserverInterface
{
    /**
     * @param TradeazeCache $tradeazeCache
     */
    public function __construct(
        protected readonly TradeazeCache $tradeazeCache
    ) {
    }

    /**
     * Flush Tradeaze cache when config is saved
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $this->tradeazeCache->clean();
    }
}
