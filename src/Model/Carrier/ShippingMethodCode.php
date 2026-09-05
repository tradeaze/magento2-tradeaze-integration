<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model\Carrier;

use DateTime;
use DateTimeInterface;
use Magento\Framework\Validator\Exception as ValidatorException;

/**
 * The shipping method code the quote hands to Magento and the delivery request reads back
 *
 * The quote and the order are separated by however long the customer takes to pay, so the
 * code is the only thing carrying the quoted delivery window across that gap. Both sides go
 * through this class so the format has one definition rather than a writer and a reader that
 * can drift apart.
 */
class ShippingMethodCode
{
    /**
     * Current format: the delivery option id, then the quoted window start in store-local time
     */
    private const ABSOLUTE_PATTERN = '/^(.+)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})$/';

    /**
     * Deprecated format - do not extend it, and do not use it as a model for anything new.
     * Kept only so orders placed before the absolute format, and not yet sent, still go
     * through. It cannot express a date, so it resolves against the current date and may
     * land on the wrong day. Unsent orders can park in AWAITING PAYMENT indefinitely, so
     * retiring this needs a deliberate cut-off rather than waiting for them to drain.
     */
    private const RELATIVE_PATTERN = '/^(.+)_(TODAY|TOMORROW)(\d{2})(\d{2})$/';

    /**
     * @param string $deliveryOptionId
     * @param int $hour
     * @param int $minute
     * @param int|null $year Null for a deprecated relative code, which carries no date
     * @param int|null $month
     * @param int|null $day
     * @param int $daysFromToday Only meaningful for a relative code
     */
    private function __construct(
        private readonly string $deliveryOptionId,
        private readonly int $hour,
        private readonly int $minute,
        private readonly ?int $year = null,
        private readonly ?int $month = null,
        private readonly ?int $day = null,
        private readonly int $daysFromToday = 0
    ) {
    }

    /*
     * Named constructors, so the two sides of the format ask for it by intent rather than
     * assembling strings. Magento2.Functions.StaticFunction objects to static methods on the
     * grounds that plugins cannot intercept them, which is the point here - the encoding is a
     * fixed contract between the quote and the delivery request, not behaviour to extend.
     */
    // phpcs:disable Magento2.Functions.StaticFunction

    /**
     * Build the code offered to the customer for a quoted delivery option
     *
     * @param string $deliveryOptionId
     * @param DateTimeInterface $windowStart Store-local; the offset belongs to the quoted day
     * @return self
     */
    public static function forQuotedWindowStart(string $deliveryOptionId, DateTimeInterface $windowStart): self
    {
        return new self(
            $deliveryOptionId,
            (int) $windowStart->format('H'),
            (int) $windowStart->format('i'),
            (int) $windowStart->format('Y'),
            (int) $windowStart->format('m'),
            (int) $windowStart->format('d')
        );
    }

    /**
     * Read back the code Magento stored on the order, carrier prefix and all
     *
     * @param string $shippingMethod e.g. "tradeaze_CAR_EVENING_202602070814"
     * @return self
     * @throws ValidatorException
     */
    public static function fromShippingMethod(string $shippingMethod): self
    {
        $prefix = Tradeaze::CARRIER_CODE . '_';

        if (!str_starts_with($shippingMethod, $prefix)) {
            throw new ValidatorException(
                __('Invalid Tradeaze shipping method format: %1', $shippingMethod),
            );
        }

        $code = substr($shippingMethod, strlen($prefix));

        if (preg_match(self::ABSOLUTE_PATTERN, $code, $matches) === 1) {
            $year = (int) $matches[2];
            $month = (int) $matches[3];
            $day = (int) $matches[4];
            $hour = (int) $matches[5];
            $minute = (int) $matches[6];

            // The pattern only constrains digit count, and DateTime silently rolls an
            // impossible date forward - "31 February" becomes 3 March - so check it here.
            if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
                throw new ValidatorException(
                    __('Invalid Tradeaze shipping method date: %1', $shippingMethod),
                );
            }

            return new self($matches[1], $hour, $minute, $year, $month, $day);
        }

        if (preg_match(self::RELATIVE_PATTERN, $code, $matches) === 1) {
            return new self(
                $matches[1],
                (int) $matches[3],
                (int) $matches[4],
                null,
                null,
                null,
                $matches[2] === 'TOMORROW' ? 1 : 0
            );
        }

        throw new ValidatorException(
            __('Invalid Tradeaze shipping method format: %1', $shippingMethod),
        );
    }

    // phpcs:enable Magento2.Functions.StaticFunction

    /**
     * The code as Magento stores it against the rate, without the carrier prefix
     *
     * @return string
     */
    public function methodCode(): string
    {
        if ($this->carriesADate()) {
            return $this->deliveryOptionId . '_' . sprintf(
                '%04d%02d%02d%02d%02d',
                $this->year,
                $this->month,
                $this->day,
                $this->hour,
                $this->minute
            );
        }

        return $this->deliveryOptionId
            . '_' . ($this->daysFromToday === 1 ? 'TOMORROW' : 'TODAY')
            . sprintf('%02d%02d', $this->hour, $this->minute);
    }

    /**
     * The Tradeaze delivery option the customer chose
     *
     * @return string
     */
    public function deliveryOptionId(): string
    {
        return $this->deliveryOptionId;
    }

    /**
     * Whether the code names the delivery day, rather than leaving it to be guessed
     *
     * @return bool
     */
    public function carriesADate(): bool
    {
        return $this->year !== null;
    }

    /**
     * The instant the delivery was quoted to start
     *
     * A relative code cannot say which day it meant, so it resolves against the current
     * date and may land on the wrong one.
     *
     * @param DateTime $storeNow The current time in the store timezone
     * @return DateTime
     */
    public function resolveStartDate(DateTime $storeNow): DateTime
    {
        $date = clone $storeNow;

        if ($this->carriesADate()) {
            $date->setDate($this->year, $this->month, $this->day);
        } elseif ($this->daysFromToday !== 0) {
            $date->modify(sprintf('+%d day', $this->daysFromToday));
        }

        $date->setTime($this->hour, $this->minute, 0);

        return $date;
    }
}
