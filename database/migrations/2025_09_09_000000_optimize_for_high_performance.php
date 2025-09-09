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
        if (Schema::hasTable('actions')) {
            $this->addIndexIfNotExists('actions', 'idx_actions_order_user_status', '(`order_id`,`user_id`,`status`)');
            $this->addIndexIfNotExists('actions', 'idx_actions_status_updated', '(`status`,`updated_at`)');
            $this->addIndexIfNotExists('actions', 'idx_actions_order_status', '(`order_id`,`status`)');
        }

        // Optimize orders table
        if (Schema::hasTable('orders')) {
            $this->addIndexIfNotExists('orders', 'idx_orders_status_created', '(`status`,`created_at`)');
            $this->addIndexIfNotExists('orders', 'idx_orders_completion', '(`done_count`,`total_count`,`status`)');
        }

        // Set MySQL optimizations
        $this->setMySQLOptimizations();
    }

    public function down()
    {
        // Drop the indexes if they exist
        if (Schema::hasTable('actions')) {
            $this->dropIndexIfExists('actions', 'idx_actions_order_user_status');
            $this->dropIndexIfExists('actions', 'idx_actions_status_updated');
            $this->dropIndexIfExists('actions', 'idx_actions_order_status');
        }

        if (Schema::hasTable('orders')) {
            $this->dropIndexIfExists('orders', 'idx_orders_status_created');
            $this->dropIndexIfExists('orders', 'idx_orders_completion');
        }
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
            // extract variable name (e.g. max_connections) from the SQL
            if (!preg_match("/SET\s+GLOBAL\s+([^\s=]+)\s*=.*/i", $sql, $m)) {
                echo "⚠️ Skipping invalid SQL: {$sql}\n";
                continue;
            }

            $variable = $m[1];

            try {
                // Use PDO quoting instead of prepared binding for SHOW GLOBAL VARIABLES
                $pdo = DB::getPdo();
                $quoted = $pdo->quote($variable);
                $row = DB::selectOne("SHOW GLOBAL VARIABLES LIKE {$quoted}");

                if (!$row) {
                    echo "ℹ️ Skipped unsupported variable: {$variable}\n";
                    continue;
                }

                // Use unprepared to avoid COM_STMT_PREPARE / prepared-statement issues on some drivers
                DB::unprepared($sql);
                echo "✅ Applied: {$sql}\n";
            } catch (\Exception $e) {
                // Don't rethrow; this migration should not break on servers without SUPER privileges
                echo "⚠️ Failed: {$sql} - {$e->getMessage()}\n";
            }
        }
    }

    private function addIndexIfNotExists(string $table, string $indexName, string $columns)
    {
        $exists = $this->indexExists($table, $indexName);
        if ($exists) {
            echo "ℹ️ Index already exists: {$table}.{$indexName}\n";
            return;
        }

        try {
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` {$columns}");
            echo "✅ Added index {$indexName} on {$table}\n";
        } catch (\Exception $e) {
            echo "⚠️ Failed to add index {$indexName} on {$table} - {$e->getMessage()}\n";
        }
    }

    private function dropIndexIfExists(string $table, string $indexName)
    {
        $exists = $this->indexExists($table, $indexName);
        if (! $exists) {
            echo "ℹ️ Index not present, skipping drop: {$table}.{$indexName}\n";
            return;
        }

        try {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
            echo "✅ Dropped index {$indexName} on {$table}\n";
        } catch (\Exception $e) {
            echo "⚠️ Failed to drop index {$indexName} on {$table} - {$e->getMessage()}\n";
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $res = DB::selectOne(
                'SELECT COUNT(1) as c FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$table, $indexName]
            );

            return isset($res->c) ? ((int)$res->c > 0) : false;
        } catch (\Exception $e) {
            // If the check fails, be conservative and assume it exists to avoid duplicate creation
            echo "⚠️ indexExists check failed for {$table}.{$indexName} - {$e->getMessage()}\n";
            return true;
        }
    }
};
