<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Observer\Adminhtml;

use Magento\Framework\Event\Observer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Model\Cache\Tradeaze as TradeazeCache;
use Tradeaze\ApiIntegration\Observer\Adminhtml\ConfigChange;

class ConfigChangeTest extends TestCase
{
    private ConfigChange $observer;
    private TradeazeCache&MockObject $tradeazeCache;

    protected function setUp(): void
    {
        $this->tradeazeCache = $this->createMock(TradeazeCache::class);
        $this->observer = new ConfigChange($this->tradeazeCache);
    }

    public function testExecuteFlushesCache(): void
    {
        $observer = $this->createMock(Observer::class);

        $this->tradeazeCache->expects($this->once())
            ->method('clean');

        $this->observer->execute($observer);
    }
}
