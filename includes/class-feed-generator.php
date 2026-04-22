<?php
defined('ABSPATH') || exit;

class GPF_Feed_Generator {

    private const FEED_SUBDIR     = 'feeds';
    private const FEED_FILE       = 'google-products.xml';
    private const CHUNK_SIZE      = 20;
    private const MAX_LOG_ENTRIES = 20;

    // ── Public entry point ────────────────────────────────────────────────

    public static function generate(): bool {
        if (!class_exists('WooCommerce')) {
            self::log('error', 'WooCommerce is not active.');
            return false;
        }

        @ini_set('memory_limit', '512M');

        try {
            self::log('info', 'Feed generation started.');
            $count = self::stream_to_file();
            update_option('gpf_feed_last_generated', current_time('mysql'));
            self::log('success', "Feed saved successfully. {$count} item(s) written.");
            return true;

        } catch (Exception $e) {
            self::log('error', 'Exception: ' . $e->getMessage());
            return false;
        }
    }

    // ── Feed URL ──────────────────────────────────────────────────────────

    public static function get_feed_url(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['baseurl']) . self::FEED_SUBDIR . '/' . self::FEED_FILE;
    }

    // ── Stream XML directly to file ───────────────────────────────────────

    private static function stream_to_file(): int {
        $upload = wp_upload_dir();
        $dir    = trailingslashit($upload['basedir']) . self::FEED_SUBDIR;

        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        $index = trailingslashit($dir) . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, '<?php // Silence is golden');
        }

        $tmp  = trailingslashit($dir) . self::FEED_FILE . '.tmp';
        $file = trailingslashit($dir) . self::FEED_FILE;

        $handle = fopen($tmp, 'w');
        if (!$handle) {
            throw new Exception('Could not open temp file for writing: ' . $tmp);
        }

        fwrite($handle, '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL);
        fwrite($handle, '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . PHP_EOL);
        fwrite($handle, '  <channel>' . PHP_EOL);
        fwrite($handle, '    <title>Auraa Design Product Feed</title>' . PHP_EOL);
        fwrite($handle, '    <link>https://www.auraadesign.co.uk</link>' . PHP_EOL);
        fwrite($handle, '    <description>Google Shopping Product Feed</description>' . PHP_EOL);

        $page        = 1;
        $total_items = 0;
        $skipped     = 0;

        while (true) {
            $products = wc_get_products([
                'status'   => 'publish',
                'limit'    => self::CHUNK_SIZE,
                'page'     => $page,
                'paginate' => false,
            ]);

            if (empty($products)) break;

            $fetched = count($products); // save count BEFORE unsetting

            foreach ($products as $product) {
                try {
                    if ($product->get_type() === 'simple') {
                        $item = self::simple_item($product);
                        if ($item) {
                            fwrite($handle, $item . PHP_EOL);
                            $total_items++;
                        }

                    } elseif ($product->get_type() === 'variable') {
                        $count = self::write_variable_items($handle, $product);
                        $total_items += $count;
                    }
                } catch (Exception $e) {
                    self::log('warning', "Skipped product ID {$product->get_id()}: {$e->getMessage()}");
                    $skipped++;
                }

                unset($product);
            }

            unset($products);
            gc_collect_cycles();

            if ($fetched < self::CHUNK_SIZE) break; // use saved count
            $page++;

            usleep(10000); // 10ms breath between chunks
        }

        fwrite($handle, '  </channel>' . PHP_EOL);
        fwrite($handle, '</rss>' . PHP_EOL);
        fclose($handle);

        if ($total_items === 0) {
            @unlink($tmp);
            throw new Exception('No items were written. Check that products are published.');
        }

        if ($skipped > 0) {
            self::log('warning', "{$skipped} product(s) skipped due to errors.");
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new Exception('Failed to move feed file into place.');
        }

        return $total_items;
    }

    // ── Simple product → one item ─────────────────────────────────────────

    private static function simple_item(WC_Product $product): string {
        $image = wp_get_attachment_image_url($product->get_image_id(), 'full');

        return self::item_xml([
            'id'           => $product->get_id(),
            'title'        => $product->get_name(),
            'description'  => $product->get_description() ?: $product->get_short_description(),
            'link'         => $product->get_permalink(),
            'image_link'   => $image ?: '',
            'availability' => self::availability($product->get_stock_status()),
            'price'        => self::format_price($product->get_price()),
            'mpn'          => $product->get_sku(),
            'product_type' => self::product_type($product),
        ]);
    }

    // ── Variable product → write each variation directly to file handle ───

    private static function write_variable_items($handle, WC_Product_Variable $product): int {
        $count         = 0;
        $base_desc     = $product->get_description() ?: $product->get_short_description();
        $base_image    = wp_get_attachment_image_url($product->get_image_id(), 'full');
        $product_type  = self::product_type($product);
        $variation_ids = $product->get_children();

        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_visible()) {
                unset($variation);
                continue;
            }

            $attributes  = $variation->get_variation_attributes();
            $attr_suffix = self::format_attributes($attributes);
            $title       = $attr_suffix
                ? $product->get_name() . ' - ' . $attr_suffix
                : $product->get_name();

            $var_image_id = $variation->get_image_id();
            $image        = $var_image_id
                ? wp_get_attachment_image_url($var_image_id, 'full')
                : $base_image;

            $item = self::item_xml([
                'id'            => $variation->get_id(),
                'title'         => $title,
                'description'   => $base_desc,
                'link'          => $variation->get_permalink(),
                'image_link'    => $image ?: '',
                'availability'  => self::availability($variation->get_stock_status()),
                'price'         => self::format_price($variation->get_price()),
                'mpn'           => $variation->get_sku() ?: $product->get_sku(),
                'item_group_id' => $product->get_id(),
                'product_type'  => $product_type,
            ]);

            fwrite($handle, $item . PHP_EOL);
            $count++;

            unset($variation);
        }

        return $count;
    }

    // ── Render one <item> block ───────────────────────────────────────────

    private static function item_xml(array $f): string {
        $item  = '    <item>' . PHP_EOL;
        $item .= '      <g:id>'           . self::esc($f['id'])          . '</g:id>'           . PHP_EOL;
        $item .= '      <g:title>'        . self::esc($f['title'])       . '</g:title>'        . PHP_EOL;
        $item .= '      <g:description>'  . self::esc($f['description']) . '</g:description>'  . PHP_EOL;
        $item .= '      <g:link>'         . self::esc($f['link'])        . '</g:link>'         . PHP_EOL;
        $item .= '      <g:image_link>'   . self::esc($f['image_link'])  . '</g:image_link>'   . PHP_EOL;
        $item .= '      <g:availability>' . $f['availability']           . '</g:availability>' . PHP_EOL;
        $item .= '      <g:price>'        . self::esc($f['price'])       . '</g:price>'        . PHP_EOL;

        if (!empty($f['mpn'])) {
            $item .= '      <g:mpn>' . self::esc($f['mpn']) . '</g:mpn>' . PHP_EOL;
        }

        $item .= '      <g:condition>new</g:condition>' . PHP_EOL;

        if (!empty($f['item_group_id'])) {
            $item .= '      <g:item_group_id>' . self::esc($f['item_group_id']) . '</g:item_group_id>' . PHP_EOL;
        }

        if (!empty($f['product_type'])) {
            $item .= '      <g:product_type>' . self::esc($f['product_type']) . '</g:product_type>' . PHP_EOL;
        }

        $item .= '      <g:identifier_exists>no</g:identifier_exists>' . PHP_EOL;
        $item .= '    </item>';

        return $item;
    }

    // ── Logging ───────────────────────────────────────────────────────────

    public static function log(string $level, string $message): void {
        $logs   = get_option('gpf_feed_log', []);
        $logs[] = [
            'time'    => current_time('mysql'),
            'level'   => $level,
            'message' => $message,
        ];

        if (count($logs) > self::MAX_LOG_ENTRIES) {
            $logs = array_slice($logs, -self::MAX_LOG_ENTRIES);
        }

        update_option('gpf_feed_log', $logs);
    }

    public static function get_logs(): array {
        return array_reverse(get_option('gpf_feed_log', []));
    }

    public static function clear_logs(): void {
        update_option('gpf_feed_log', []);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function esc(mixed $val): string {
        return htmlspecialchars((string) $val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function availability(string $stock_status): string {
        return match ($stock_status) {
            'instock'     => 'in_stock',
            'onbackorder' => 'preorder',
            default       => 'out_of_stock',
        };
    }

    private static function format_price(string $price): string {
        if (!$price) return '';
        return number_format((float) $price, 2, '.', '') . ' GBP';
    }

    private static function product_type(WC_Product $product): string {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (empty($terms) || is_wp_error($terms)) return '';
        return implode(' > ', array_map(fn($t) => $t->name, array_slice($terms, 0, 3)));
    }

    private static function format_attributes(array $attributes): string {
        $parts = [];
        foreach ($attributes as $name => $value) {
            if (!$value) continue;
            $label   = ucfirst(str_replace(['attribute_pa_', 'attribute_'], '', $name));
            $parts[] = $label . ': ' . $value;
        }
        return implode(', ', $parts);
    }
}
