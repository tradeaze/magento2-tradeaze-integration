# Changelog

All notable changes to this module are recorded here. Versions follow
[semantic versioning](https://semver.org/).

## [1.1.0] - 2026-08-18

### Deliveries are no longer created until the order is paid

Previously a Tradeaze delivery was booked as soon as the order was placed. That
works for gateways that charge the card immediately, but not for authorise-only,
buy-now-pay-later or offsite/redirect providers — those orders sit in
"processing" with no money taken. The result was deliveries booked against
orders that hadn't been paid for, and in some cases never were.

Nothing is now sent to Tradeaze until the full order value has been captured.
Orders that aren't paid yet are parked with a new **AWAITING PAYMENT** status and
a note on the order, and the retry cron (every 5 minutes) creates the delivery
once the payment comes through.

What you'll notice:

- A new **AWAITING PAYMENT** status in the Tradeaze column on the sales grid,
  and as a filter option.
- Orders on immediate-capture gateways behave exactly as before — the delivery
  is still booked at checkout.
- Orders on pay-later or offsite gateways now show a short delay between
  placement and the delivery appearing in Tradeaze.
- If a store part-invoices orders as a matter of course, those orders stay in
  AWAITING PAYMENT until the balance is captured.

Orders placed before upgrading are not backfilled. Any unsent orders from before
the upgrade need handling manually.

### Refreshed product page branding

The on-site messaging graphic on the product page (`tradeaze-osm.png`) has been
updated to the current brand artwork. SVG versions of the lockup and the
logo-only mark are now bundled for themes that want to use them.

Requires a static content deploy — see below.

### For developers

- `Observer\Sales\OrderPlaceAfter` and `Cron\ReTryFailedTradeazeOrders` each take
  a new constructor argument, `Service\OrderPaymentStatus`. If you have a
  preference or plugin constructing either class directly, update it.
- New `Service\OrderPaymentStatus::isPaidInFull()` — checks `base_total_paid`
  against `base_grand_total` rather than trusting the order state, so it behaves
  the same across payment providers.
- New constant `Service\Tradeaze::AWAITING_PAYMENT_STATUS`.
- The retry cron now also picks up parked orders, skips anything that already has
  a delivery, and processes oldest first with a page size of 100 (was 20).
- Unit tests added for the order place observer and extended for the retry cron.
- A pull request template was added to the repository.

### Upgrading

```
composer update tradeaze/magento2-tradeaze-integration
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

No configuration changes needed.

## [1.0.0] - 2026-05-04

First public release.

[1.1.0]: https://github.com/tradeaze/magento2-tradeaze-integration/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/tradeaze/magento2-tradeaze-integration/releases/tag/v1.0.0
