<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Services\BulkActionService;
use App\Jobs\HighPerformanceActionProcessor;

/**
 * High-performance MQTT controller - works alongside your existing MqttResponseController
 * This is an ALTERNATIVE endpoint, your existing /mqtt/response continues to work unchanged
 */
class HighPerformanceMqttController extends Controller
{
    private $bulkService;

    public function __construct()
    {
        // Only instantiate if service exists to avoid errors
        if (class_exists(BulkActionService::class)) {
            $this->bulkService = app(BulkActionService::class);
        }
    }

    /**
     * Alternative high-performance endpoint - keeps your existing /mqtt/response unchanged
     */
    public function handle(Request $request)
    {
        $startTime = microtime(true);

        // Use same validation as your existing controller
        try {
            $validated = $request->validate([
                'order_id' => 'required|integer',
                'user_id' => 'required|integer',
                'status' => 'required|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Invalid request data'], 422);
        }

        $orderId = $validated['order_id'];
        $userId = $validated['user_id'];
        $rawStatus = strtolower(trim($validated['status']));

        // Same status normalization as your existing controller
        $allowed = ['done', 'external'];
        if (in_array($rawStatus, $allowed, true)) {
            $status = $rawStatus;
        } elseif ($rawStatus === 'busy') {
            return response()->json([
                'accepted' => true,
                'message' => 'Device busy - status ignored'
            ], 202);
        } else {
            return response()->json([
                'accepted' => true,
                'message' => 'Unknown status ignored'
            ], 202);
        }

        try {
            $processingMode = env('MQTT_PROCESSING_MODE', 'queue'); // Default to your current system

            switch ($processingMode) {
                case 'bulk':
                    return $this->handleBulkMode($orderId, $userId, $status, $startTime);

                case 'queue':
                default:
                    // Use your existing queue system for safety
                    return $this->handleQueueMode($orderId, $userId, $status, $startTime);
            }

        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            \Log::error("[HIGH_PERF_MQTT] Error processing action", [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'duration_ms' => $duration,
                'error' => $e->getMessage()
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Bulk processing mode - only if you enable it
     */
    private function handleBulkMode($orderId, $userId, $status, $startTime)
    {
        if (!$this->bulkService) {
            // Fallback to queue mode if bulk service not available
            return $this->handleQueueMode($orderId, $userId, $status, $startTime);
        }

        $this->bulkService->addAction($orderId, $userId, $status);
        $this->bulkService->autoFlush();

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'success' => true,
            'message' => 'Action buffered for bulk processing',
            'mode' => 'bulk',
            'duration_ms' => $duration,
            'pending_count' => $this->bulkService->getPendingCount()
        ]);
    }

    /**
     * Queue mode - uses your existing Redis queue system
     */
    private function handleQueueMode($orderId, $userId, $status, $startTime)
    {
        $actionData = [
            'order_id' => $orderId,
            'user_id' => $userId,
            'status' => $status,
            'type' => 'follow',
            'timestamp' => now()->toDateTimeString(),
            'ip' => request()->ip(),
        ];

        // Use same Redis key and logic as your existing system
        $redisKey = 'mqtt_actions_queue';
        Redis::rpush($redisKey, json_encode($actionData));
        Redis::ltrim($redisKey, -1000, -1);
        Redis::expire($redisKey, 3600);

        $queueSize = Redis::llen($redisKey);

        // Use your existing job dispatch logic
        $this->ensureProcessorRunning();

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'success' => true,
            'message' => 'Action queued for processing',
            'mode' => 'queue',
            'duration_ms' => $duration,
            'queue_size' => $queueSize
        ]);
    }

    private function ensureProcessorRunning()
    {
        // Only dispatch high-performance processor if explicitly enabled
        if (env('HIGH_PERFORMANCE_ENABLED', false)) {
            $lockKey = 'high_performance_processor_running';

            if (!Cache::has($lockKey)) {
                Cache::put($lockKey, true, now()->addMinutes(5));
                HighPerformanceActionProcessor::dispatch()->delay(now()->addSeconds(1));
            }
        }
        // Otherwise, rely on your existing ActionQueueJob dispatch mechanism
    }

    /**
     * Status endpoint to monitor both systems
     */
    public function status()
    {
        $mode = env('MQTT_PROCESSING_MODE', 'queue');
        $stats = [];

        // Current queue status (your existing system)
        $stats['existing_queue'] = [
            'queue_size' => Redis::llen('mqtt_actions_queue'),
            'prefixed_queue_size' => Redis::llen('laravel_database_mqtt_actions_queue'),
            'action_queue_running' => Cache::has('action_queue_job_running')
        ];

        // New system status
        switch ($mode) {
            case 'bulk':
                if ($this->bulkService) {
                    $stats['bulk_service'] = [
                        'pending_actions' => $this->bulkService->getPendingCount(),
                        'batch_size' => env('BULK_ACTION_BATCH_SIZE', 100)
                    ];
                }
                break;
        }

        $stats['high_performance'] = [
            'enabled' => env('HIGH_PERFORMANCE_ENABLED', false),
            'processor_running' => Cache::has('high_performance_processor_running')
        ];

        return response()->json([
            'mode' => $mode,
            'stats' => $stats,
            'server_load' => sys_getloadavg(),
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
        ]);
    }
}
