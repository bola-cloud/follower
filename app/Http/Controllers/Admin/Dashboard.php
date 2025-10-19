<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use App\Models\User;
use App\Models\Order;
use App\Models\Action;
use Illuminate\Support\Facades\DB;

class Dashboard extends Controller
{
    public function index(Request $request)
    {
        // Clear activation set on each dashboard load so the UI starts from zero
        // and we can re-ping devices to compute the current active count.
        try {
            \Illuminate\Support\Facades\Redis::connection('queue')->del('device_activations_set');
            Cache::forget('device_activations_set');
            Cache::forget('device_activations_count');
            \Illuminate\Support\Facades\Log::info('[Dashboard] cleared device_activations_set on page load');
        } catch (\Throwable $e) {
            // Fallback to cache forget if Redis is unavailable
            Cache::forget('device_activations_set');
            Cache::forget('device_activations_count');
            \Illuminate\Support\Facades\Log::warning('[Dashboard] failed to clear device_activations_set on page load', ['error' => $e->getMessage()]);
        }

        // ✅ 2. Run the Node.js script to trigger MQTT
        $scriptPath = base_path('node_scripts/mqtt_ping_devices.cjs');
        $logFile = storage_path('logs/mqtt_ping.log');
        $command = "node {$scriptPath} >> {$logFile} 2>&1 &";
        exec($command);

        // Statistics
        $usersCount = User::where('type','user')->count();
        $orders = Order::selectRaw('MONTH(created_at) as month, COUNT(*) as total')
            ->groupBy('month')->pluck('total', 'month')->toArray();
        $ordersTotal = Order::count();
        $ordersCompleted = Order::where('status', 'completed')->count();
        $ordersPending = $ordersTotal - $ordersCompleted;

        $ordersDoneTotal = \DB::table('orders')->sum('done_count');
        $ordersTotalCount = \DB::table('orders')->sum('total_count');
        $ordersRemainingTotal = max(0, $ordersTotalCount - $ordersDoneTotal);

        // Activation count: attempt to read from Redis, fallback to cache
            // read the activations count from queue redis
            $activationCount = 0;
            try {
                $redis = \Illuminate\Support\Facades\Redis::connection('queue');
                try {
                    $redisConfig = config('database.redis.queue');
                    \Illuminate\Support\Facades\Log::info('[Dashboard] redis.queue.config', $redisConfig);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[Dashboard] failed to read redis.queue.config', ['error' => $e->getMessage()]);
                }
                $activationCount = $redis->scard('device_activations_set');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[Dashboard] failed to read device_activations_set', ['error' => $e->getMessage()]);
            }
            \Illuminate\Support\Facades\Log::info('[Dashboard] activationCount read', ['connection' => 'queue', 'count' => $activationCount]);

        return view('admin.dashboard', compact(
            'usersCount',
            'orders',
            'ordersTotal',
            'ordersCompleted',
            'ordersPending',
            'activationCount'
        ));
    }

    public function users()
    {
        $users = User::selectRaw('MONTH(created_at) as month, COUNT(*) as count')
            ->where('type', 'user')
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month');

        $labels = [];
        $data = [];

        foreach (range(1, 12) as $month) {
            $labels[] = date('F', mktime(0, 0, 0, $month, 10));
            $data[] = $users[$month] ?? 0;
        }

        return response()->json(['labels' => $labels, 'data' => $data]);
    }

    public function actions()
    {
        $actionStats = Action::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return response()->json([
            'labels' => $actionStats->keys(),
            'data' => $actionStats->values(),
        ]);
    }
}
