<?php
/**
 * One-time script to recalculate all target_url_hash values in orders table
 * This fixes hash mismatches that prevent proper duplicate detection
 *
 * Run with: php fix-target-url-hashes.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Starting target_url_hash recalculation...\n";

// Count total orders
$total = DB::table('orders')->count();
echo "Total orders: {$total}\n";

// Recalculate ALL hashes using the correct normalization
// This matches the PHP normalizeUrl() function exactly:
// 1. TRIM spaces
// 2. Remove protocol
// 3. Remove www
// 4. Remove query params
// 5. Remove fragments
// 6. Remove trailing slashes
// 7. Lowercase
$updated = DB::statement("
    UPDATE orders
    SET target_url_hash = SHA1(
        LOWER(
            TRIM(TRAILING '/' FROM
                REGEXP_REPLACE(
                    REGEXP_REPLACE(
                        REGEXP_REPLACE(TRIM(target_url), '\\\\\\\\?.*$', ''),
                        '#.*$', ''
                    ),
                    '^(https?://)?(www\\\\\\\\.)?', ''
                )
            )
        )
    )
");

echo "Recalculation complete!\n";

// Verify: Check for orders with same URL but different hashes (should be 0)
$duplicateHashes = DB::select("
    SELECT
        target_url,
        COUNT(DISTINCT target_url_hash) as hash_count,
        GROUP_CONCAT(DISTINCT target_url_hash) as hashes
    FROM orders
    WHERE target_url IS NOT NULL
    GROUP BY target_url
    HAVING hash_count > 1
    LIMIT 10
");

if (count($duplicateHashes) > 0) {
    echo "\nWARNING: Found URLs with multiple different hashes:\n";
    foreach ($duplicateHashes as $row) {
        echo "  URL: {$row->target_url}\n";
        echo "  Hashes: {$row->hashes}\n";
        echo "  Count: {$row->hash_count}\n\n";
    }
} else {
    echo "\n✓ Verification passed: All URLs have consistent hashes\n";
}

// Show sample of recalculated hashes
$samples = DB::table('orders')
    ->select('id', 'target_url', 'target_url_hash')
    ->whereNotNull('target_url_hash')
    ->limit(5)
    ->get();

echo "\nSample recalculated hashes:\n";
foreach ($samples as $order) {
    echo "  Order #{$order->id}: {$order->target_url}\n";
    echo "    Hash: {$order->target_url_hash}\n\n";
}

echo "Done!\n";
