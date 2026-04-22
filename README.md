# Google Product Feed

A WordPress plugin that automatically generates a Google Shopping product feed from WooCommerce and keeps it updated every 2 days.

---

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 8.0+

---

## Installation

1. Upload the `google-product-feed` folder to `wp-content/plugins/`
2. Go to **WordPress Admin → Plugins**
3. Find **Google Product Feed** and click **Activate**
4. Go to **WooCommerce → Google Feed** to generate your first feed

---

## File Structure

```
wp-content/plugins/google-product-feed/
├── google-product-feed.php       # Main plugin file
├── uninstall.php                 # Cleanup on plugin deletion
├── includes/
│   ├── index.php                 # Directory protection
│   ├── class-feed-generator.php  # XML builder and file writer
│   └── class-feed-scheduler.php  # WP-Cron scheduler
└── admin/
    ├── index.php                 # Directory protection
    └── class-feed-admin.php      # Admin dashboard page
```

---

## How It Works

### Feed Generation
The plugin fetches all published WooCommerce products in chunks of 20 and streams each item directly to disk. It never holds the entire catalog in memory, making it safe for stores with thousands of products.

- **Simple products** produce one `<item>` each
- **Variable products** produce one `<item>` per variation, all sharing the same `<g:item_group_id>`
- **Grouped and external products** are skipped

### File Storage
The feed is saved to:
```
wp-content/uploads/feeds/google-products.xml
```
Writes are atomic — the plugin writes to a `.tmp` file first, then renames it into place. This prevents Google from ever reading a half-written file.

### Scheduling
The feed regenerates automatically every 2 days using WP-Cron. The scheduler is self-healing — if the cron event goes missing for any reason, it is automatically rescheduled on the next page load without any manual intervention.

---

## Admin Dashboard

Go to **WooCommerce → Google Feed** to:

- View the **feed URL** to submit to Google Merchant Center
- See when the feed was **last generated**
- See when the **next scheduled run** is
- **Manually trigger** a regeneration at any time
- View the **activity log** for the last 20 events
- **Clear the log**

---

## Feed URL

Once generated, the feed is publicly accessible at:

```
https://yourdomain.com/wp-content/uploads/feeds/google-products.xml
```

The exact URL is displayed on the admin page. Submit this URL to Google Merchant Center.

---

## Google Merchant Center Setup

1. Go to [merchants.google.com](https://merchants.google.com)
2. Navigate to **Products → Feeds → Add feed**
3. Choose **Scheduled fetch**
4. Paste your feed URL
5. Set fetch frequency to **Daily**

Google will fetch daily. The plugin refreshes every 2 days, so Google will always have data no more than 2 days old.

---

## Feed Format

Each product item follows the Google Merchant Center specification:

```xml
<item>
  <g:id>12345</g:id>
  <g:title>Product Name - Color: Red, Size: L</g:title>
  <g:description>Product description here</g:description>
  <g:link>https://yourdomain.com/product/?attribute_pa_color=red</g:link>
  <g:image_link>https://yourdomain.com/wp-content/uploads/image.jpg</g:image_link>
  <g:availability>in_stock</g:availability>
  <g:price>99.99 GBP</g:price>
  <g:mpn>SKU123</g:mpn>
  <g:condition>new</g:condition>
  <g:item_group_id>99999</g:item_group_id>
  <g:product_type>Category > Subcategory</g:product_type>
  <g:identifier_exists>no</g:identifier_exists>
</item>
```

### Field Mapping

| Google Field | Source |
|---|---|
| `g:id` | WooCommerce product or variation ID |
| `g:title` | Product name + variation attributes |
| `g:description` | Full description, falls back to short description |
| `g:link` | Product permalink with variation attributes as query params |
| `g:image_link` | Variation image, falls back to parent product image |
| `g:availability` | Mapped from WooCommerce stock status |
| `g:price` | WooCommerce price in GBP |
| `g:mpn` | SKU — variation SKU, falls back to parent SKU |
| `g:condition` | Always `new` |
| `g:item_group_id` | Parent product ID (variable products only) |
| `g:product_type` | First 3 product categories joined with `>` |
| `g:identifier_exists` | Always `no` |

### Stock Status Mapping

| WooCommerce | Google Feed |
|---|---|
| `instock` | `in_stock` |
| `onbackorder` | `preorder` |
| anything else | `out_of_stock` |

---

## WP-Cron Reliability

WP-Cron only fires when someone visits the site. On low-traffic sites the scheduled run may be delayed. To guarantee it fires on time, ask your server administrator to add a real cron job that pings WordPress every 15 minutes:

```bash
*/15 * * * * curl -s https://yourdomain.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

You can verify the scheduled event is registered using the [WP Crontrol](https://wordpress.org/plugins/wp-crontrol/) plugin — look for the `gpf_generate_feed` event under **Tools → Cron Events**.

---

## Troubleshooting

**Feed shows fewer items than expected**
Check the activity log for any `WARNING` entries — these indicate products that were skipped due to errors.

**Feed not generating / memory error**
The plugin sets a 512M memory limit during generation. If your host enforces a lower hard limit, contact your host to increase `memory_limit` in `php.ini`.

**Next Scheduled Run shows a warning**
The cron event is missing. It will self-heal on the next page load. If it persists, deactivate and reactivate the plugin.

**Feed URL returns 404**
The feed has not been generated yet. Go to **WooCommerce → Google Feed** and click **Generate Feed Now**.

---

## Uninstalling

Deleting the plugin from **WordPress Admin → Plugins → Delete** will automatically:

- Remove all plugin options from the database
- Cancel the scheduled cron event
- Delete the generated `google-products.xml` file

---

## Changelog

### 1.1.0
- Streaming file writer to handle large catalogs without memory exhaustion
- Self-healing cron scheduler
- Activity log visible in admin dashboard
- XML validation before overwriting live feed
- PHP and WooCommerce version checks on activation
- Directory browsing protection
- Clean uninstall via `uninstall.php`

### 1.1.0
- Initial release
