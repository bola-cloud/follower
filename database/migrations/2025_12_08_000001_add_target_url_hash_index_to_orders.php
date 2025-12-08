<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddTargetUrlHashIndexToOrders extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add index only if it does not already exist to avoid duplicate key errors
        if (!\DB::select("SHOW INDEX FROM `orders` WHERE Key_name = ?", ['idx_orders_target_url_hash'])) {
            Schema::table('orders', function (Blueprint $table) {
                // Add index on target_url_hash for fast lookups in batchCheckEligibility
                $table->index('target_url_hash', 'idx_orders_target_url_hash');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Drop index only if it exists
        if (\DB::select("SHOW INDEX FROM `orders` WHERE Key_name = ?", ['idx_orders_target_url_hash'])) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('idx_orders_target_url_hash');
            });
        }
    }
}
