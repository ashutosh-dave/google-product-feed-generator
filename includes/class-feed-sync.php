<?php
defined('ABSPATH') || exit;

class GPF_Feed_Sync {

    // ── Paths ─────────────────────────────────────────────────────────────
    private const DEST_DIR  = '/var/www/vhosts/next-staging.auraadesign.co.uk/public/feeds/';
    private const DEST_FILE = '/var/www/vhosts/next-staging.auraadesign.co.uk/public/feeds/google-products.xml';
    private const DEST_TMP  = '/var/www/vhosts/next-staging.auraadesign.co.uk/public/feeds/google-products.xml.tmp';

    private static function get_source_file(): string {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'feeds/google-products.xml';
    }

    // ── Domain rewrite ────────────────────────────────────────────────────

    private const FROM_DOMAIN = 'dev.auraadesign.co.uk';
    private const TO_DOMAIN   = 'next-staging.auraadesign.co.uk';

    // ── Retry settings ────────────────────────────────────────────────────

    private const MAX_RETRIES  = 3;
    private const RETRY_DELAY  = 2;

    // ── Public entry point ────────────────────────────────────────────────

    public static function sync(): bool {
        GPF_Feed_Generator::log('info', 'Feed sync started.');
        self::save_status('running', 'Sync in progress...');

        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            GPF_Feed_Generator::log('info', "Sync attempt {$attempt} of " . self::MAX_RETRIES . '.');

            try {
                $bytes = self::run();
                self::save_status('success', 'Synced successfully. ' . self::format_bytes($bytes) . ' written.');
                GPF_Feed_Generator::log('success', 'Feed synced to Next.js successfully.');
                return true;

            } catch (Exception $e) {
                GPF_Feed_Generator::log('warning', "Attempt {$attempt} failed: " . $e->getMessage());
                self::save_status('retrying', "Attempt {$attempt} failed: " . $e->getMessage());

                if ($attempt < self::MAX_RETRIES) {
                    sleep(self::RETRY_DELAY);
                }
            }
        }

        $error = 'Sync failed after ' . self::MAX_RETRIES . ' attempts. Previous feed kept in place.';
        self::save_status('failed', $error);
        GPF_Feed_Generator::log('error', $error);
        self::notify_admin($error);

        return false;
    }

    // ── Core sync logic ───────────────────────────────────────────────────

    private static function run(): int {

        $source_file = self::get_source_file();

// 1. Source file must exist and be readable
if (!file_exists($source_file)) {
    throw new Exception('Source file does not exist: ' . $source_file);
}

if (!is_readable($source_file)) {
    throw new Exception('Source file is not readable: ' . $source_file);
}

// 2. Source file must not be empty
$source_size = filesize($source_file);
if ($source_size === 0) {
    throw new Exception('Source file is empty — aborting to protect existing feed.');
}

// 3. Read source XML
$xml = file_get_contents($source_file);
if ($xml === false) {
    throw new Exception('Failed to read source file: ' . $source_file);
}

        // 4. Rewrite domain in all URLs
        $xml = self::rewrite_domain($xml);

        // 5. Validate XML before touching destination
        if (!self::validate_xml($xml)) {
            throw new Exception('XML validation failed after domain rewrite — aborting sync.');
        }

        // 6. Ensure destination directory exists
        if (!file_exists(self::DEST_DIR)) {
            if (!mkdir(self::DEST_DIR, 0755, true)) {
                throw new Exception('Could not create destination directory: ' . self::DEST_DIR);
            }
            file_put_contents(self::DEST_DIR . 'index.php', '<?php // Silence is golden');
        }

        if (!is_writable(self::DEST_DIR)) {
            throw new Exception('Destination directory is not writable: ' . self::DEST_DIR);
        }

        // 7. Write to .tmp first — never touch live file until we're sure
        $bytes = file_put_contents(self::DEST_TMP, $xml);
        if ($bytes === false) {
            throw new Exception('Failed to write to temp file: ' . self::DEST_TMP);
        }

        if ($bytes === 0) {
            @unlink(self::DEST_TMP);
            throw new Exception('Wrote 0 bytes to temp file — aborting.');
        }

        // 8. Atomically replace live file
        if (!rename(self::DEST_TMP, self::DEST_FILE)) {
            @unlink(self::DEST_TMP);
            throw new Exception('Failed to move temp file to destination: ' . self::DEST_FILE);
        }

        // 9. Verify destination file size matches
        clearstatcache(true, self::DEST_FILE);
        $dest_size = filesize(self::DEST_FILE);
        if ($dest_size !== strlen($xml)) {
            throw new Exception("File size mismatch — expected " . strlen($xml) . " bytes, got {$dest_size}.");
        }

        GPF_Feed_Generator::log('info', "Synced {$dest_size} bytes from {$source_file} to " . self::DEST_FILE);

        return $dest_size;
    }

    // ── Save sync status to DB ────────────────────────────────────────────

    private static function save_status(string $status, string $message): void {
        update_option('gpf_sync_status', [
            'status'  => $status,
            'message' => $message,
            'time'    => current_time('mysql'),
        ]);
    }

    // ── Read sync status from DB ──────────────────────────────────────────

    public static function get_status(): array {
        return get_option('gpf_sync_status', [
            'status'  => 'never',
            'message' => 'Feed has not been synced yet.',
            'time'    => null,
        ]);
    }

    // ── Get destination feed URL ──────────────────────────────────────────

    public static function get_feed_url(): string {
        return 'https://' . self::TO_DOMAIN . '/feeds/google-products.xml';
    }

    // ── Domain rewrite ────────────────────────────────────────────────────

    private static function rewrite_domain(string $xml): string {
        $rewritten = str_replace(self::FROM_DOMAIN, self::TO_DOMAIN, $xml);

        if ($rewritten === $xml) {
            GPF_Feed_Generator::log('warning', 'Domain rewrite made no changes — check FROM_DOMAIN constant.');
        }

        return $rewritten;
    }

    // ── XML validation ────────────────────────────────────────────────────

    private static function validate_xml(string $xml): bool {
        libxml_use_internal_errors(true);
        $doc    = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if ($doc === false || !empty($errors)) {
            foreach ($errors as $error) {
                GPF_Feed_Generator::log('error', 'XML error: ' . trim($error->message));
            }
            return false;
        }

        return true;
    }

    // ── Admin email notification ──────────────────────────────────────────

    private static function notify_admin(string $error): void {
        $admin_email = get_option('admin_email');
        $site_name   = get_option('blogname');
        $subject     = "[{$site_name}] Google Product Feed — Sync Failed";

        $body  = "Hello," . PHP_EOL . PHP_EOL;
        $body .= "The Google Product Feed plugin failed to sync the feed to the Next.js server." . PHP_EOL . PHP_EOL;
        $body .= "Error:" . PHP_EOL;
        $body .= $error . PHP_EOL . PHP_EOL;
        $body .= "The previous feed file has been kept in place on the Next.js server so Google Merchant Center continues to work." . PHP_EOL . PHP_EOL;
        $body .= "Please check the activity log in WordPress Admin → WooCommerce → Google Feed for more details." . PHP_EOL . PHP_EOL;
        $body .= "Time: " . current_time('mysql') . PHP_EOL;
        $body .= "Site: " . get_site_url() . PHP_EOL;

        $sent = wp_mail($admin_email, $subject, $body);

        if ($sent) {
            GPF_Feed_Generator::log('info', "Failure notification sent to {$admin_email}.");
        } else {
            GPF_Feed_Generator::log('warning', "Could not send failure notification to {$admin_email}.");
        }
    }

    // ── Helper ────────────────────────────────────────────────────────────

    private static function format_bytes(int $bytes): string {
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 2) . ' KB';
        return $bytes . ' bytes';
    }
}