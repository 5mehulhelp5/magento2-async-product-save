# Mohan_ProductQueueSave — Magento 2 Async Product Save

A Magento 2 module that adds a **"Save via Queue & Close"** button to the admin product edit page. Instead of saving the product synchronously (which can time out on large catalogues or complex products), it pushes the save operation onto Magento's message queue and processes it in the background via a long-running PHP consumer.

---

## Features

- Async product save via `Magento_MessageQueue` — no browser timeout
- Full support for **all product types**:
  - **Simple products** — all attributes, custom options, tier prices, links
  - **Configurable products** — variation creation and sync (status, price, qty, images per child)
  - **Grouped products** — associated product links with default quantities
  - **Bundle products** — bundle options and selections
  - **Downloadable products** — link files, link samples, and standalone samples
  - **Virtual products** — treated the same as simple
- Media gallery — new uploads, role assignment, image removal
- Tier prices
- Custom options
- Related / upsell / cross-sell links
- Category assignments
- Website assignments
- Stock (qty, in-stock flag)
- Multiselect attribute values
- Admin queue log grid — see pending, processing, success, and failed jobs
- Nightly cleanup cron — removes completed queue messages automatically (keeps DB size in check)
- CLI status command: `bin/magento mohan:product-queue:status`

---

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | ≥ 8.1 |
| Magento | 2.4.x |
| `magento/module-catalog` | * |
| `magento/module-configurable-product` | * |
| `magento/module-catalog-inventory` | * |

---

## Installation

### Via Composer (recommended)

```bash
composer require mohan-devstack/magento2-product-queue-save
php bin/magento module:enable Mohan_ProductQueueSave
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### Manual

1. Copy the module files into `app/code/Mohan/ProductQueueSave/`
2. Run:

```bash
php bin/magento module:enable Mohan_ProductQueueSave
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

---

## Configuration

Go to **Stores → Configuration → Mohan → Product Queue Save**.

| Setting | Description | Default |
|---------|-------------|---------|
| Enable Module | Show/hide the queue save button | Yes |
| Max Retry Attempts | How many times a failed job is retried | 3 |

---

## Starting the Queue Consumer

The consumer is a long-running PHP process. Start it on your server:

```bash
php bin/magento queue:consumers:start mohan.product.queue.save.consumer
```

> **Important:** Run the consumer as the **same OS user as your web server** (typically `www-data`). Uploaded files in the media tmp directory are owned by the web server user — the consumer needs write access to that directory to move files to permanent storage.

For production, manage it with **Supervisor** so it restarts automatically on crash:

```ini
[program:mohan_product_queue]
command=php /var/www/html/magento/bin/magento queue:consumers:start mohan.product.queue.save.consumer
user=www-data
autostart=true
autorestart=true
stderr_logfile=/var/log/mohan_product_queue.err.log
stdout_logfile=/var/log/mohan_product_queue.out.log
```

---

## Usage

1. Open any product in the Magento admin.
2. Make your changes.
3. Click **"Save via Queue & Close"** (next to the standard Save button).
4. You are redirected to the product grid immediately — the save happens in the background.
5. Monitor progress at **Catalog → Product Queue Log**.

---

## Queue Log Grid

The admin grid at **Catalog → Product Queue Log** shows every queued save with:

- Product ID and SKU
- Status: `pending` → `processing` → `success` / `failed`
- Timestamp and error message (on failure)

---

## How It Works

```
[Admin clicks button]
        │
        ▼
Controller (Queue/Save)
  → Collects full product POST data
  → Publishes JSON message to queue topic: mohan.product.queue.save
  → Returns success immediately → browser redirects to grid
        │
        ▼
Queue Consumer (long-running CLI process)
  → Unserializes message
  → Loads existing product from repository
  → Applies: stock, tier prices, links, custom options, gallery, categories
  → Runs type-specific processors:
      • ConfigurableProcessor  — creates/syncs child variations and images
      • GroupedProcessor       — saves associated links with default qty
      • BundleProcessor        — saves bundle options and selections
      • DownloadableProcessor  — moves link/sample files and saves extension attributes
  → productRepository->save()
  → Marks log entry as success / failed
```

### Why not just use standard save?

Magento's synchronous product save can take 30–120 seconds for:
- Configurable products with 50+ variations
- Products with large media galleries (100+ images)
- Catalogues with deep category trees
- Downloadable products with multiple file links

This module moves that work off the HTTP request entirely.

---

## Database Table

The module creates one table: **`mohan_product_queue_log`**

| Column | Type | Description |
|--------|------|-------------|
| `log_id` | int | Primary key |
| `message_id` | varchar(64) | Unique queue message ID |
| `product_id` | int | Catalog product entity ID |
| `sku` | varchar(64) | Product SKU |
| `status` | varchar(32) | pending / processing / success / failed / retry |
| `store_id` | smallint | Store scope of the save |
| `admin_user_id` | int | Admin who triggered the save |
| `error_message` | text | Error detail on failure |
| `retry_count` | smallint | Number of retries attempted |
| `created_at` | timestamp | When the job was queued |
| `processed_at` | timestamp | When it completed |

---

## Cleanup Cron

A nightly cron (`0 3 * * *`) automatically:

1. Deletes completed `queue_message` / `queue_message_status` rows for this module's queue — `queue_message.body` is `longtext` and grows large with image data.
2. Trims `mohan_product_queue_log`: success entries older than **30 days**, failed/retry entries older than **90 days**.

---

## CLI Command

Check queue status from the command line:

```bash
php bin/magento mohan:product-queue:status
```

---

## Known Limitations

- **New image uploads**: The source `.tmp` file must still exist on disk when the consumer runs. If Magento's `remove_old_catalog_images_from_cache` cron cleans the tmp directory before the consumer processes the message, the image will be skipped (product still saves, just without the new image). This is unlikely in practice but worth noting for very busy queues.
- **Retry**: Automatic retry is intentionally disabled. A retry would re-publish the queue message, but the original payload is not stored in the log table, so retrying with an empty payload would wipe tier prices and product links. Manual re-save is the safe path until full payload persistence is implemented.

---

## License

MIT © [Mohan](mailto:mohandevstack@gmail.com)
