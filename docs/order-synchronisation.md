# Order synchronisation

Tradeaze delivery creation follows Magento's programmatic order state rather than payment-provider-specific status labels or webhooks.

## Lifecycle

```text
Tradeaze order placed
        |
        +-- Magento state is processing --> create immediately
        |
        +-- any earlier state -----------> AWAITING_PROCESSING
                                                |
                         +----------------------+--------------------+
                         |                                           |
             order commits processing                    order canceled/closed
                         |                                           |
                create immediately                             NOT_REQUIRED
                  +------+------+
                  |             |
               PENDING     FAILEDSYNC1
             (ID stored)        |
                       five-minute cron retry
                                |
                         FAILEDSYNC2-4
                           then FAILED
```

Magento order **states** are stable workflow values used programmatically. Store owners and payment integrations can configure multiple order **statuses** under a state, so this module deliberately checks `Order::STATE_PROCESSING` rather than requiring a status code named `processing`.

## Observers and worker

- `sales_order_place_after` records `AWAITING_PROCESSING` when the order is not ready and preserves the resolved inventory source.
- `sales_order_save_commit_after` immediately synchronizes an awaiting Tradeaze order when Magento commits `STATE_PROCESSING`. Repeated saves are ignored once the status has changed or a Tradeaze delivery ID exists.
- `tradeaze_apiintegration_retryfailedtradeazeorders` runs every five minutes. It recovers an awaiting order if the update event was missed and retains the existing failed-sync retry sequence.

The shared synchronizer requires a non-empty delivery ID before recording `PENDING`. Invalid responses, transport exceptions, and PHP errors enter the failed-sync retry path instead of being recorded as successful.

## Logs

The module writes structured PSR-3 context for deferred, attempted, successful, failed, and no-longer-required outcomes. Context includes the Magento increment ID, entity ID where available, order state/status, Tradeaze sync status, attempt number, source code where relevant, and returned delivery ID.

The API key and customer address/contact data are never deliberately included in these synchronization log entries.

## Operations

The Sales > Orders grid exposes the following local states:

- `AWAITING_PROCESSING`: Magento has not committed the order in its processing state.
- `FAILEDSYNC1` through `FAILEDSYNC4`: a creation attempt failed and will be retried.
- `FAILED`: retry limit reached; manual investigation is required.
- `NOT_REQUIRED`: Magento was canceled or closed before creation.
