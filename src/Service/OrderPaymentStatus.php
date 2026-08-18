<?php
/**
 * Copyright © Tradeaze Ltd. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Tradeaze\ApiIntegration\Service;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Provider agnostic "has this order been paid for in full?" check
 *
 * Deliberately derived from the core sales totals rather than from the order state, because
 * the state says nothing about whether money actually arrived:
 *
 * - Magento\Sales\Model\Order\Payment::place() sets STATE_PROCESSING for *any* payment action
 *   before the action runs, and Payment::processAction() only reaches capture() for
 *   authorize_capture. An authorize-only or order-action payment (Stripe, Braintree, PayPal,
 *   Mollie pay-later and friends) therefore lands in "processing" with nothing captured.
 * - Redirect/offsite providers take their placement state from the $stateObject they populate
 *   in initialize(), so each provider picks its own state ("new", "pending_payment", ...).
 *   Any hard coded list of states is a bet on one provider's conventions.
 *
 * base_total_paid, by contrast, is only ever incremented by Magento\Sales\Model\Order\Invoice::pay(),
 * which every provider funnels through when money is captured, and which accumulates correctly
 * across partial invoices.
 */
class OrderPaymentStatus
{
    /**
     * Rounding tolerance for the totals comparison, in base currency units
     *
     * Grand total and total paid are stored as decimals and are summed from per-invoice values,
     * so an exact equality test can miss by a fraction of a penny on split invoices.
     */
    public const AMOUNT_TOLERANCE = 0.0001;

    /**
     * Whether the full order value has been captured
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isPaidInFull(OrderInterface $order): bool
    {
        $grandTotal = $order->getBaseGrandTotal();

        // No totals on the order means we cannot make a judgement - never assume "paid"
        if ($grandTotal === null) {
            return false;
        }

        return ((float) $grandTotal - (float) $order->getBaseTotalPaid()) <= self::AMOUNT_TOLERANCE;
    }
}
