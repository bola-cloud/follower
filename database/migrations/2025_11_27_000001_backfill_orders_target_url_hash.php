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
     */
    public function up()
    {
        // Backfill missing or empty hashes using SHA1(TRIM(TRAILING '/' FROM target_url))
        DB::statement("UPDATE orders SET target_url_hash = SHA1(TRIM(TRAILING '/' FROM target_url)) WHERE target_url_hash IS NULL OR target_url_hash = ''");

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
