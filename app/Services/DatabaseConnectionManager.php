<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Connection;

class DatabaseConnectionManager
{
    private static $connections = [];
    private static $connectionCount = 0;
    private const MAX_CONNECTIONS = 10;
    private const CONNECTION_TIMEOUT = 30;

    
    /**
     * Get a dedicated connection for batch operations
     */
    public static function getBatchConnection(): Connection
    {
        $connectionId = 'batch_' . (self::$connectionCount % self::MAX_CONNECTIONS);

        if (!isset(self::$connections[$connectionId])) {
            self::$connections[$connectionId] = self::createOptimizedConnection();
            self::$connectionCount++;
        }

        return self::$connections[$connectionId];
    }

    /**
     * Create an optimized database connection for batch operations
     */
    private static function createOptimizedConnection(): Connection
    {
        $config = config('database.connections.mysql');

        // Create unique connection name for batch operations
        $connectionName = 'batch_' . uniqid();

        // Optimize connection for batch operations
        $config['options'] = array_merge($config['options'] ?? [], [
            \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            \PDO::MYSQL_ATTR_FOUND_ROWS => true,
            \PDO::ATTR_TIMEOUT => self::CONNECTION_TIMEOUT,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            // Optimize for batch inserts
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION', innodb_lock_wait_timeout=10"
        ]);

        // Register new connection configuration
        config(['database.connections.' . $connectionName => $config]);

        // Create and return the new connection
        $connection = DB::connection($connectionName);

        // Ensure the connection is established
        $connection->getPdo();

        Log::debug("Created optimized batch connection: {$connectionName}");

        return $connection;
    }

    /**
     * Execute batch operation with dedicated connection
     */
    public static function executeBatch(callable $operation): mixed
    {
        $connection = self::getBatchConnection();

        // Set connection-specific optimizations
        $connection->statement("SET SESSION autocommit=0");
        $connection->statement("SET SESSION unique_checks=0");
        $connection->statement("SET SESSION foreign_key_checks=0");

        try {
            $result = $operation($connection);

            // Re-enable checks
            $connection->statement("SET SESSION unique_checks=1");
            $connection->statement("SET SESSION foreign_key_checks=1");
            $connection->statement("SET SESSION autocommit=1");

            return $result;

        } catch (\Exception $e) {
            // Re-enable checks even on error
            $connection->statement("SET SESSION unique_checks=1");
            $connection->statement("SET SESSION foreign_key_checks=1");
            $connection->statement("SET SESSION autocommit=1");

            throw $e;
        }
    }

    /**
     * Close all managed connections
     */
    public static function closeConnections(): void
    {
        foreach (self::$connections as $connectionId => $connection) {
            try {
                $connection->disconnect();
                Log::debug("Closed batch connection: {$connectionId}");
            } catch (\Exception $e) {
                Log::warning('Error closing database connection', [
                    'connection_id' => $connectionId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        self::$connections = [];
        self::$connectionCount = 0;
        Log::info("All batch connections closed and pool reset");
    }

    /**
     * Get connection pool statistics
     */
    public static function getPoolStats(): array
    {
        return [
            'active_connections' => count(self::$connections),
            'total_created' => self::$connectionCount,
            'max_connections' => self::MAX_CONNECTIONS
        ];
    }
}
