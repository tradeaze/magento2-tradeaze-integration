<?php
// phpcs:ignoreFile
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Test\Unit\Ui\Component\Listing\Column;

use PHPUnit\Framework\TestCase;
use Tradeaze\ApiIntegration\Service\Tradeaze;
use Tradeaze\ApiIntegration\Ui\Component\Listing\Column\TradeazeStatus;

class TradeazeStatusTest extends TestCase
{
    private TradeazeStatus $statusSource;

    protected function setUp(): void
    {
        $this->statusSource = new TradeazeStatus();
    }

    public function testToOptionArrayContainsAllStatuses(): void
    {
        $options = $this->statusSource->toOptionArray();
        $values = array_column($options, 'value');

        $this->assertContains(Tradeaze::PENDING_STATUS, $values);
        $this->assertContains('CONFIRMED', $values);
        $this->assertContains('DELIVERED', $values);
        $this->assertContains('REJECTED', $values);
        $this->assertContains('CANCELLED', $values);
        $this->assertContains('FAILED', $values);
        $this->assertContains(Tradeaze::AWAITING_PROCESSING_STATUS, $values);
        $this->assertNotContains('QUEUED', $values);
        $this->assertContains(Tradeaze::NOT_REQUIRED_STATUS, $values);
    }

    public function testToOptionArrayContainsAllFailedSyncStatuses(): void
    {
        $options = $this->statusSource->toOptionArray();
        $values = array_column($options, 'value');

        for ($i = 1; $i <= Tradeaze::MAX_NUMBER_OF_REATTEMPTS; $i++) {
            $this->assertContains(
                'FAILEDSYNC' . $i,
                $values,
                "FAILEDSYNC{$i} should be in the option list"
            );
        }
    }

    public function testToOptionArrayHasCorrectCount(): void
    {
        $options = $this->statusSource->toOptionArray();

        // 5 remote statuses + 2 local sync statuses + FAILEDSYNC attempts + 1 FAILED
        $expectedCount = 5 + 2 + Tradeaze::MAX_NUMBER_OF_REATTEMPTS + 1;
        $this->assertCount($expectedCount, $options);
    }

    public function testOptionsHaveValueAndLabelKeys(): void
    {
        $options = $this->statusSource->toOptionArray();

        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }
}
