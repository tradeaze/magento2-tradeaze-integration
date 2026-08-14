<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Model;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

class DeliverySelection
{
    public const FIELD_DELIVERY_OPTION_ID = 'tradeaze_delivery_option_id';
    public const FIELD_DELIVERY_DATE = 'tradeaze_delivery_date';
    public const FIELD_WINDOW_START_UTC = 'tradeaze_window_start_utc';
    public const PERSISTED_FIELDS = [
        self::FIELD_DELIVERY_OPTION_ID,
        self::FIELD_DELIVERY_DATE,
        self::FIELD_WINDOW_START_UTC,
    ];

    /**
     * @param string $deliveryOptionId
     * @param string $deliveryDate
     * @param string $windowStartUtc
     */
    private function __construct(
        private readonly string $deliveryOptionId,
        private readonly string $deliveryDate,
        private readonly string $windowStartUtc,
    ) {
    }

    /**
     * Create a delivery selection from one Tradeaze quote option.
     *
     * @param array $option
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- Immutable value-object named constructor.
    public static function fromQuoteOption(array $option): self
    {
        return self::create(
            $option['deliveryOptionId'] ?? $option['id'] ?? null,
            $option['deliveryDate'] ?? null,
            $option['windowStart'] ?? null,
        );
    }

    /**
     * Restore a delivery selection from Magento model data.
     *
     * @param array $data
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- Immutable value-object named constructor.
    public static function fromPersistedData(array $data): self
    {
        return self::create(
            $data[self::FIELD_DELIVERY_OPTION_ID] ?? null,
            $data[self::FIELD_DELIVERY_DATE] ?? null,
            $data[self::FIELD_WINDOW_START_UTC] ?? null,
        );
    }

    /**
     * Restore an order created with the pre-absolute-date shipping method format.
     *
     * Legacy relative dates are anchored to the immutable order creation time so a
     * retry after midnight cannot move the delivery to another day.
     *
     * @param string $shippingMethod
     * @param DateTimeImmutable $orderCreatedAt
     * @param DateTimeZone $storeTimezone
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- Immutable value-object named constructor.
    public static function fromLegacyShippingMethod(
        string $shippingMethod,
        DateTimeImmutable $orderCreatedAt,
        DateTimeZone $storeTimezone,
    ): self {
        $matches = [];
        $matchResult = preg_match(
            '/^tradeaze_(.+)_(TODAY|TOMORROW)(\d{2})(\d{2})$/',
            $shippingMethod,
            $matches,
        );

        if ($matchResult !== 1) {
            throw new InvalidArgumentException('Invalid legacy Tradeaze shipping method.');
        }

        $hour = (int) $matches[3];
        $minute = (int) $matches[4];
        if ($hour > 23 || $minute > 59) {
            throw new InvalidArgumentException('Invalid legacy Tradeaze delivery time.');
        }

        $windowStart = $orderCreatedAt->setTimezone($storeTimezone);
        if ($matches[2] === 'TOMORROW') {
            $windowStart = $windowStart->modify('+1 day');
        }

        $windowStart = $windowStart->setTime($hour, $minute);

        return new self(
            $matches[1],
            $windowStart->format('Y-m-d'),
            self::formatUtc($windowStart),
        );
    }

    /**
     * Validate and canonicalize a delivery selection.
     *
     * @param mixed $deliveryOptionId
     * @param mixed $deliveryDate
     * @param mixed $windowStart
     * @return self
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- Shared validation for named constructors.
    private static function create(mixed $deliveryOptionId, mixed $deliveryDate, mixed $windowStart): self
    {
        if (!is_string($deliveryOptionId) || trim($deliveryOptionId) === '') {
            throw new InvalidArgumentException('Tradeaze deliveryOptionId is required.');
        }

        $parsedDeliveryDate = is_string($deliveryDate)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $deliveryDate)
            : false;
        if (!is_string($deliveryDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)
            || !$parsedDeliveryDate || $parsedDeliveryDate->format('Y-m-d') !== $deliveryDate) {
            throw new InvalidArgumentException('Tradeaze deliveryDate must use YYYY-MM-DD.');
        }

        if (!is_string($windowStart)
            || !preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/',
                $windowStart
            )
        ) {
            throw new InvalidArgumentException('Tradeaze windowStart must be an absolute ISO-8601 timestamp.');
        }

        try {
            $parsedWindowStart = new DateTimeImmutable($windowStart);
        } catch (\Exception $e) {
            throw new InvalidArgumentException('Tradeaze windowStart is invalid.', 0, $e);
        }

        return new self(
            $deliveryOptionId,
            $deliveryDate,
            self::formatUtc($parsedWindowStart),
        );
    }

    /**
     * Format an absolute timestamp in UTC without discarding fractional precision.
     *
     * @param DateTimeImmutable $date
     * @return string
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction -- Immutable value-object formatting helper.
    private static function formatUtc(DateTimeImmutable $date): string
    {
        $utcDate = $date->setTimezone(new DateTimeZone('UTC'));
        $fraction = rtrim($utcDate->format('u'), '0');

        return $utcDate->format('Y-m-d\TH:i:s') . ($fraction === '' ? '' : '.' . $fraction) . 'Z';
    }

    /**
     * Return the exact Tradeaze delivery option identifier.
     *
     * @return string
     */
    public function getDeliveryOptionId(): string
    {
        return $this->deliveryOptionId;
    }

    /**
     * Return Tradeaze's timezone-free delivery date.
     *
     * @return string
     */
    public function getDeliveryDate(): string
    {
        return $this->deliveryDate;
    }

    /**
     * Return the selected window start as a canonical UTC timestamp.
     *
     * @return string
     */
    public function getWindowStartUtc(): string
    {
        return $this->windowStartUtc;
    }

    /**
     * Return a deterministic carrier method code that fits Magento's 120-character limit.
     *
     * @return string
     */
    public function getMethodCode(): string
    {
        $identity = implode("\0", [
            $this->deliveryOptionId,
            $this->deliveryDate,
            $this->windowStartUtc,
        ]);

        return 'delivery_' . hash('sha256', $identity);
    }

    /**
     * Return the fields persisted on Magento quote rates, quote addresses, and orders.
     *
     * @return array<string, string>
     */
    public function toPersistedData(): array
    {
        return [
            self::FIELD_DELIVERY_OPTION_ID => $this->deliveryOptionId,
            self::FIELD_DELIVERY_DATE => $this->deliveryDate,
            self::FIELD_WINDOW_START_UTC => $this->windowStartUtc,
        ];
    }

    /**
     * Return the fields used to create a Tradeaze delivery.
     *
     * @return array<string, string>
     */
    public function toDeliveryRequestData(): array
    {
        return [
            'deliveryOptionId' => $this->deliveryOptionId,
            'startTime' => $this->windowStartUtc,
        ];
    }
}
