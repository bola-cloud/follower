<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class BackfillOrdersTargetUrlHash extends Migration
{
    /**
     * Run the migrations.
     * Backfill `target_url_hash` for legacy rows and add an index.
     * Normalize URLs by removing protocol, www, query params, fragments, and trailing slashes.
     */
    public function up()
    {
        // Backfill missing or empty hashes using normalized URL:
        // 1. Remove protocol (http:// or https://)
        // 2. Remove www. prefix
        // 3. Remove query string (everything after ?)
        // 4. Remove fragment (everything after #)
        // 5. Remove trailing slashes
        // 6. Convert to lowercase
        DB::statement("
            UPDATE orders 
            SET target_url_hash = SHA1(
                LOWER(
                    TRIM(TRAILING '/' FROM 
                        REGEXP_REPLACE(
                            REGEXP_REPLACE(
                                REGEXP_REPLACE(target_url, '\\\\?.*$', ''),
                                '#.*$', ''
                            ),
                            '^(https?://)?(www\\\\.)?', ''
                        )
                    )
                )
            )
            WHERE target_url_hash IS NULL OR target_url_hash = ''
        ");

        // Add index if it doesn't already exist. Use Schema::table with try/catch to avoid migration failing
        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->index('target_url_hash', 'idx_orders_target_url_hash');
            });
        } catch (\Throwable $e) {
            // If index already exists or DB doesn't support, ignore and continue
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('idx_orders_target_url_hash');
            });
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
