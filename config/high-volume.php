<?php

return [

    /*
    |--------------------------------------------------------------------------
    | High Volume Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for handling 500-1000 concurrent users per order
    | Includes circuit breaker, database pooling, and queue management settings
    |
    */

    'enabled' => env('HIGH_VOLUME_PROCESSING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Settings
    |--------------------------------------------------------------------------
    */
    'circuit_breaker' => [
        'failure_threshold' => env('CIRCUIT_BREAKER_THRESHOLD', 5),
        'timeout_seconds' => env('CIRCUIT_BREAKER_TIMEOUT', 60),
        'half_open_max_calls' => env('CIRCUIT_BREAKER_HALF_OPEN_CALLS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connection Management
    |--------------------------------------------------------------------------
    */
    'database' => [
        'max_connection_percent' => env('DB_MAX_CONNECTION_PERCENT', 80),
        'connection_pool_size' => env('DB_CONNECTION_POOL_SIZE', 10),
        'batch_size_normal' => env('DB_BATCH_SIZE_NORMAL', 500),
        'batch_size_high_load' => env('DB_BATCH_SIZE_HIGH_LOAD', 100),
        'query_timeout_seconds' => env('DB_QUERY_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Management
    |--------------------------------------------------------------------------
    */
    'queues' => [
        'high_volume_queue' => env('HIGH_VOLUME_QUEUE_NAME', 'high_volume_actions_queue'),
        'max_queue_size' => env('MAX_QUEUE_SIZE', 5000),
        'batch_dispatch_threshold' => env('BATCH_DISPATCH_THRESHOLD', 1000),
        'batch_dispatch_cooldown' => env('BATCH_DISPATCH_COOLDOWN', 30), // seconds
        'job_timeout' => env('QUEUE_JOB_TIMEOUT', 180),
        'retry_attempts' => env('QUEUE_RETRY_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | System Load Thresholds
    |--------------------------------------------------------------------------
    */
    'thresholds' => [
        'warning' => [
            'db_connection_percent' => env('WARNING_DB_CONNECTIONS', 70),
            'queue_size' => env('WARNING_QUEUE_SIZE', 1000),
            'redis_memory_mb' => env('WARNING_REDIS_MEMORY', 512),
            'failed_jobs' => env('WARNING_FAILED_JOBS', 10),
        ],
        'critical' => [
            'db_connection_percent' => env('CRITICAL_DB_CONNECTIONS', 85),
            'queue_size' => env('CRITICAL_QUEUE_SIZE', 2000),
            'redis_memory_mb' => env('CRITICAL_REDIS_MEMORY', 1024),
            'failed_jobs' => env('CRITICAL_FAILED_JOBS', 50),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Optimization
    |--------------------------------------------------------------------------
    */
    'performance' => [
        'enable_connection_pooling' => env('ENABLE_CONNECTION_POOLING', true),
        'enable_batch_processing' => env('ENABLE_BATCH_PROCESSING', true),
        'enable_adaptive_scaling' => env('ENABLE_ADAPTIVE_SCALING', true),
        'health_check_interval' => env('HEALTH_CHECK_INTERVAL', 30), // seconds
        'metrics_retention_hours' => env('METRICS_RETENTION_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring and Alerting
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'log_health_metrics' => env('LOG_HEALTH_METRICS', true),
        'alert_on_critical' => env('ALERT_ON_CRITICAL', true),
        'webhook_url' => env('HEALTH_ALERT_WEBHOOK_URL'),
        'alert_cooldown_minutes' => env('ALERT_COOLDOWN_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Load Testing Support
    |--------------------------------------------------------------------------
    */
    'testing' => [
        'max_concurrent_orders' => env('MAX_CONCURRENT_ORDERS', 10),
        'max_users_per_order' => env('MAX_USERS_PER_ORDER', 1000),
        'enable_test_mode' => env('ENABLE_TEST_MODE', false),
        'test_mode_batch_size' => env('TEST_MODE_BATCH_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    */
    'redis' => [
        'high_volume_key_prefix' => env('REDIS_HIGH_VOLUME_PREFIX', 'hv:'),
        'metrics_key_prefix' => env('REDIS_METRICS_PREFIX', 'metrics:'),
        'cache_ttl' => env('REDIS_CACHE_TTL', 3600),
        'queue_ttl' => env('REDIS_QUEUE_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Graceful Degradation
    |--------------------------------------------------------------------------
    */
    'degradation' => [
        'enable_graceful_degradation' => env('ENABLE_GRACEFUL_DEGRADATION', true),
        'fallback_to_sync_processing' => env('FALLBACK_TO_SYNC', false),
        'reduce_batch_size_on_load' => env('REDUCE_BATCH_SIZE_ON_LOAD', true),
        'queue_only_on_overload' => env('QUEUE_ONLY_ON_OVERLOAD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Scaling
    |--------------------------------------------------------------------------
    */
    'worker_scaling' => [
        'enable_auto_scaling' => env('ENABLE_AUTO_SCALING', true),
        'min_workers' => env('MIN_WORKERS', 1),
        'max_workers' => env('MAX_WORKERS', 10),
        'scale_up_threshold' => env('SCALE_UP_THRESHOLD', 500), // queue size
        'scale_down_threshold' => env('SCALE_DOWN_THRESHOLD', 100), // queue size
        'scaling_cooldown_minutes' => env('SCALING_COOLDOWN', 5),
    ],
];
