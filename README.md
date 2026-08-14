# Tradeaze_ApiIntegration for Magento 2

**Package:** `tradeaze/magento2-tradeaze-integration`
**Type:** Magento 2 Extension
**License:** OSL-3.0
**Copyright:** Tradeaze Ltd (original development by [Foundation Commerce Ltd](https://foundationcommerce.co.uk/))

**Magento Versions:** 2.4.6 - 2.4.8
**PHP Versions:** 8.2 - 8.4

---

## Overview

**Tradeaze_ApiIntegration** connects your Magento 2 store with the [Tradeaze](https://www.tradeaze.uk/) logistics platform, enabling merchants in the construction and trades industries to offer **on-demand, same-day delivery** directly from checkout.

> For a merchant-facing, step-by-step setup walkthrough (including custom-theme integration guidance), see [`docs/installation-guide.md`](docs/installation-guide.md).

---

## Key Features

- **Real-Time Delivery Quotes** - Fetches live pricing and vehicle options from the Tradeaze API during checkout.
- **Automatic Delivery Creation** - Creates deliveries in Tradeaze when orders are placed.
- **Closest Source Selection** - Uses Magento MSI to find the nearest inventory source with stock for all items, based on geographic distance to the customer's shipping address.
- **Webhook Synchronisation** - Receives order status updates from Tradeaze (confirmed, cancelled, updated, dropoff complete).
- **Automatic Retry** - Failed delivery creations are retried via cron up to 4 times before being marked as permanently failed.
- **Per-Product Eligibility** - Enable/disable Tradeaze delivery per product with configurable dimension and weight mappings.
- **On-Site Messaging** - Optional Tradeaze branding on product and basket pages for eligible products.
- **Admin Cancellation** - Cancel deliveries directly from the Magento order view.
- **Quote Caching** - Delivery quotes are cached for 5 minutes to reduce API calls. Cache is automatically flushed when Tradeaze configuration is changed.

---

## Installation

```bash
composer require tradeaze/magento2-tradeaze-integration
bin/magento module:enable Tradeaze_ApiIntegration
bin/magento setup:upgrade
bin/magento cache:flush
```

### Requirements

- Magento 2.4.6 – 2.4.8
- PHP 8.2 – 8.4
- Valid Tradeaze API token
- Magento Multi-Source Inventory (MSI) modules installed and configured
- GeoNames data imported for the target country. The integration will refuse to enable until this has been done. Attempting to save the Enabled toggle will return an error directing you to run:
  ```bash
  bin/magento inventory-geonames:import GB
  ```
  This powers distance-based source selection and is enforced by the `ValidateGeoNames` backend model on the `tradeaze_api/general/enabled` field.

---

## Configuration

### Tradeaze API Integration

**Admin > Stores > Configuration > TRADEAZE > API Integration**

Configuration is organised into four groups:

#### General

| Field | Config Path | Description |
|-------|-------------|-------------|
| Enabled | `tradeaze_api/general/enabled` | Master toggle for the integration |
| API Token | `tradeaze_api/general/api_token` | Encrypted API key, sent as `x-api-key` header. Flagged as sensitive in `di.xml`, so it is excluded from `bin/magento app:config:dump` output. |
| API Mode | `tradeaze_api/general/api_mode` | Live (production) or Test (staging) API environment |
| Create Webhooks | - | Button to register all webhook endpoints with Tradeaze |

#### Product Dimensions & Weight

Maps existing Magento product attributes to Tradeaze item dimensions. If attribute values are available, they take priority. Otherwise, the module falls back to the product's Tradeaze Size/Weight Category. Products with neither are ineligible for Tradeaze delivery.

Weight is automatically converted from lbs to kg if Magento's weight unit is configured as lbs.

| Field | Config Path | Description |
|-------|-------------|-------------|
| Length Attribute | `tradeaze_api/product_attributes/tradeaze_length_attribute` | Product attribute for length |
| Width Attribute | `tradeaze_api/product_attributes/tradeaze_width_attribute` | Product attribute for width |
| Height Attribute | `tradeaze_api/product_attributes/tradeaze_height_attribute` | Product attribute for height |
| Weight Attribute | `tradeaze_api/product_attributes/tradeaze_weight_attribute` | Product attribute for weight |
| Default Dimension Unit | `tradeaze_api/product_attributes/tradeaze_default_unit` | Unit for dimension attributes (mm/cm/m) |

#### Delivery

| Field | Config Path | Description |
|-------|-------------|-------------|
| Cutoff Time Buffer | `tradeaze_api/delivery/delivery_cutoff_time_buffer` | Minutes before cutoff that a delivery method must remain available. Default: 15 |

#### On-Site Messaging

| Field | Config Path | Description |
|-------|-------------|-------------|
| Product Page Messaging | `tradeaze_api/messaging/pdp_messaging_enabled` | Show Tradeaze messaging on product pages |
| Basket Page Messaging | `tradeaze_api/messaging/basket_messaging_enabled` | Show Tradeaze messaging on the cart page |

All fields are configurable per store view.

### Shipping Carrier

**Admin > Stores > Configuration > Sales > Delivery Methods > Tradeaze**

Standard Magento carrier settings (enabled, title, sort order, allowed countries, error message).

---

## Product Attributes

The module creates a **Tradeaze** attribute group containing:

| Attribute | Code | Type | Description |
|-----------|------|------|-------------|
| Tradeaze Enabled | `tradeaze_enabled` | Yes/No | Whether the product is eligible for Tradeaze delivery |
| Tradeaze Size Category | `tradeaze_size_category` | Select (19 options) | Fallback dimension category when per-product dimension attributes aren't populated. Options are descriptive presets such as Tiny Box (~50×50×50mm), Trade Bag (~500×350×150mm), Sheet 2400×1200mm (8'×4'), Panel / Door, Pipe 6.0m, Pallet, Bulk Bag, etc. See `Model/Product/Attribute/Source/TradeazeSizeCategory.php` for the full list. |
| Tradeaze Weight Category | `tradeaze_weight_category` | Select (10 tiers) | Fallback weight category when the weight attribute isn't populated. Tiers are `≤1 kg`, `≤5 kg`, `≤10 kg`, `≤25 kg`, `≤50 kg`, `≤100 kg`, `≤250 kg`, `≤500 kg`, `≤750 kg`, `≤1000 kg`. |

---

## Order Attributes

Orders placed with Tradeaze shipping have the following columns on `sales_order` and `sales_order_grid`:

| Column | Code | Description |
|--------|------|-------------|
| Tradeaze Order Status | `tradeaze_order_status` | Local sync states (`AWAITING_PROCESSING`, `FAILEDSYNC1-4`, `FAILED`, `NOT_REQUIRED`) and remote delivery states (`PENDING`, `CONFIRMED`, `DELIVERED`, `REJECTED`, `CANCELLED`) |
| Tradeaze Order ID | `tradeaze_order_id` | The delivery ID returned by the Tradeaze API |
| Tradeaze Source Code | `tradeaze_source_code` | The MSI inventory source code used for pickup |

All three columns are stored on `sales_order` and `sales_order_grid`. In the Sales > Orders admin grid:

- `tradeaze_order_status` is visible and filterable via a dropdown populated by `Ui\Component\Listing\Column\TradeazeStatus`.
- `tradeaze_order_id` is registered on the grid but hidden by default; enable it via the column selector to see the value.
- `tradeaze_source_code` is not surfaced on the grid UI component. It is available on the underlying order record and can be queried directly or added to a custom grid extension if needed.

---

## How It Works

### Checkout Flow

1. Customer enters a shipping address at checkout.
2. The carrier validates product eligibility (simple products only, `tradeaze_enabled`, dimensions/weight available).
3. The module resolves the **closest inventory source** that has stock for all items in the basket.
4. A delivery quote is requested from the Tradeaze API using the source's postcode as the pickup location.
5. Available vehicle options (e.g. Car, Van) with pricing are displayed as shipping methods.
6. The cutoff time buffer filters out delivery windows that are too close to expiry.

### Order Placement

1. The `checkout_submit_before` observer re-validates that the selected shipping method is still available.
2. The `sales_order_place_after` observer checks Magento's programmatic order **state**. Orders that are not yet `processing` are saved as `AWAITING_PROCESSING` instead of being silently skipped.
3. When Magento commits the order in the `processing` state, `sales_order_save_commit_after` immediately creates the Tradeaze delivery via `POST /v1/deliveries`. This works with asynchronous and redirect payment flows without depending on a specific payment provider.
4. If an order is already `processing` during placement, the same delivery synchronizer runs immediately.
5. On success: the Tradeaze order ID and source code are saved on the order, status set to `PENDING`.
6. On failure: status set to `FAILEDSYNC1`, source code still saved for cron retry.

### Failed Delivery Retry

A cron job runs every 5 minutes (`*/5 * * * *`) to recover missed processing transitions and retry failed deliveries:

- Picks up processing `AWAITING_PROCESSING` orders and `FAILEDSYNC1` through `FAILEDSYNC4` orders (batch limit: 20 per run).
- Excludes awaiting orders that have not reached Magento's standard `processing` state before applying the batch limit.
- Marks orders canceled or closed before creation as `NOT_REQUIRED`.
- Re-uses the stored source code from the original order placement.
- On success: sets status to `PENDING`.
- On failure: increments the status (e.g. `FAILEDSYNC1` > `FAILEDSYNC2`).
- After the initial failure and four failed retry attempts: marks as `FAILED` (terminal, no further retries).

See [Order synchronisation](docs/order-synchronisation.md) for state transitions, logging, and recovery guidance.

### Inventory Source Selection

The module uses Magento MSI to determine which warehouse fulfills the order:

1. Gets all enabled sources assigned to the website's stock, ordered by priority.
2. Filters to sources that have stock for **every** item in the basket.
3. If the customer's shipping address is available, sorts eligible sources by geographic distance (Haversine formula using GeoNames postcode data).
4. If geocoding fails, falls back to MSI priority ordering among stock-eligible sources.
5. If no single source can fulfill all items, the order is ineligible for Tradeaze delivery.

---

## Tradeaze API

Full API reference: [https://docs.tradeaze.app/](https://docs.tradeaze.app/)

### Authentication

All outbound API calls include:

| Header | Value |
|--------|-------|
| Accept | `application/json` |
| x-api-key | Encrypted token from admin configuration |

### Base URLs

| Mode | URL |
|------|-----|
| Live | `https://api.tradeaze.app` |
| Test | `https://stage-api.tradeaze.app` |

### Endpoints

| Purpose | Method | Endpoint |
|---------|--------|----------|
| Delivery Quote | POST | `/v1/deliveries/quote` |
| Create Delivery | POST | `/v1/deliveries` |
| Cancel Delivery | DELETE | `/v1/deliveries/{id}` |
| Register Webhook | POST | `/v1/webhooks` |

HTTP client: Guzzle 7.x with a 10-second timeout.

---

## Webhooks

Tradeaze sends webhook events to Magento REST endpoints to synchronise order statuses:

| Event | Magento Endpoint | Action |
|-------|------------------|--------|
| Order Confirmed | `POST /V1/tradeaze-api-integration/orderconfirmed` | Updates order status |
| Order Cancelled | `POST /V1/tradeaze-api-integration/ordercancelled` | Updates order status |
| Order Updated | `POST /V1/tradeaze-api-integration/orderupdated` | Updates order status |
| Dropoff Complete | `POST /V1/tradeaze-api-integration/dropoffcomplete` | Updates order status |

Webhook endpoints are registered with Tradeaze via the **Create Webhooks** button in admin configuration.

Webhook endpoints require the `Tradeaze_ApiIntegration::orderupdate` ACL permission. Tradeaze must authenticate using a Magento integration token with this permission granted.

The integration's Access Token is sent as a Bearer token in the `Authorization` header. For Magento to accept this without an OAuth 1.0a signature, enable **Stores > Configuration > Services > OAuth > Consumer Settings > Allow OAuth Access Tokens to be used as standalone Bearer tokens** (`oauth/consumer/enable_integration_as_bearer`). Without this setting, Magento rejects the webhook with a 401 response.

Register the Access Token with Tradeaze so it is included on every outbound webhook call. See the **Webhook Authentication** section of the [Tradeaze API documentation](https://docs.tradeaze.app/).

On processing failure, webhooks return HTTP 500 so Tradeaze can retry delivery. The specific error is logged but not exposed in the response.

---

## Caching

Quote responses are cached using a custom `tradeaze` cache type.

- **TTL:** 5 minutes
- **Cache key:** SHA-256 hash of the JSON request body
- **Scope:** Only delivery quote responses are cached. Delivery creation, webhooks, and cancellations are never cached.
- **Invalidation:** Time-based (5 min TTL). The cache is also flushed automatically when Tradeaze admin configuration is saved.
- **Admin:** The cache type appears in **System > Cache Management** and can be flushed manually.

The cutoff time buffer is applied **after** cache retrieval, so cached quotes still have stale delivery windows filtered out based on the current time.

---

## Admin Features

### Cancel on Tradeaze

An admin button on the order view page allows cancelling the delivery directly in Tradeaze. Requires the `Tradeaze_ApiIntegration::orderupdate` ACL permission.

### Order Grid

The Tradeaze Order Status column is added to the sales order grid with a dropdown filter containing all possible statuses.

---

## ACL Resources

| Resource | Purpose |
|----------|---------|
| `Tradeaze_ApiIntegration::config_tradeaze_api_integration` | Access to Tradeaze configuration |
| `Tradeaze_ApiIntegration::orderupdate` | Permission to cancel deliveries from admin and for inbound webhook access |

---

## Dependencies

### Magento Modules

- `Magento_Catalog`
- `Magento_Sales`
- `Magento_Inventory`
- `Magento_InventoryApi`
- `Magento_InventorySalesApi`
- `Magento_InventoryDistanceBasedSourceSelection`
- `Magento_InventoryDistanceBasedSourceSelectionApi`

### External

- `guzzlehttp/guzzle ^7.0`

---

## License

This module is open-source and released under the [OSL-3.0 LICENSE](LICENSE.txt).

---

## Maintainers

**Tradeaze Engineering Team**
[https://github.com/tradeaze/magento2-tradeaze-integration](https://github.com/tradeaze/magento2-tradeaze-integration)
