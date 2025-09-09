<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Optimize actions table for high-performance updates
        Schema::table('actions', function (Blueprint $table) {
            // Add composite index for faster lookups
            $table->index(['order_id', 'user_id', 'status'], 'idx_actions_order_user_status');

            // Add index for bulk operations
            $table->index(['status', 'updated_at'], 'idx_actions_status_updated');

            // Add index for order completion checks
            $table->index(['order_id', 'status'], 'idx_actions_order_status');
        });

        // Optimize orders table
        Schema::table('orders', function (Blueprint $table) {
            // Add index for active order queries
            $table->index(['status', 'created_at'], 'idx_orders_status_created');

            // Add index for completion checks
            $table->index(['done_count', 'total_count', 'status'], 'idx_orders_completion');
        });

        // Set MySQL optimizations
        $this->setMySQLOptimizations();
    }

    public function down()
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->dropIndex('idx_actions_order_user_status');
            $table->dropIndex('idx_actions_status_updated');
            $table->dropIndex('idx_actions_order_status');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_status_created');
            $table->dropIndex('idx_orders_completion');
        });
    }

    private function setMySQLOptimizations()
    {
        $optimizations = [
            // Connection settings
            "SET GLOBAL max_connections = 500",
            "SET GLOBAL thread_cache_size = 100",
            "SET GLOBAL table_open_cache = 4000",

            // Buffer settings for 8GB RAM
            "SET GLOBAL innodb_buffer_pool_size = '4G'",
            "SET GLOBAL innodb_log_buffer_size = '64M'",
            "SET GLOBAL query_cache_size = '256M'",
            "SET GLOBAL query_cache_limit = '2M'",

            // Performance settings
            "SET GLOBAL innodb_flush_log_at_trx_commit = 2",
            "SET GLOBAL sync_binlog = 0",
            "SET GLOBAL innodb_doublewrite = 0",

            // Bulk operation settings
            "SET GLOBAL bulk_insert_buffer_size = '64M'",
            "SET GLOBAL max_allowed_packet = '256M'",

            // Timeout settings
            "SET GLOBAL wait_timeout = 300",
            "SET GLOBAL interactive_timeout = 300"
        ];

        foreach ($optimizations as $sql) {
            try {
                DB::statement($sql);
                echo "✅ Applied: {$sql}\n";
            } catch (\Exception $e) {
                echo "⚠️ Failed: {$sql} - {$e->getMessage()}\n";
            }
        }
    }
};
