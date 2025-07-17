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
        // ✅ 1. Clear previous cache (set expires in controller, optional)
        Cache::put('device_activations_set', [], now()->addMinutes(2));
        Cache::put('device_activations_count', 0, now()->addMinutes(2));

        // ✅ 2. Run the Node.js script to trigger MQTT
        $process = new Process(['node', base_path('node_scripts/mqtt_ping_devices.cjs')]);

        try {
            $process->mustRun();
            logger('✅ MQTT ping script executed successfully');
        } catch (ProcessFailedException $e) {
            logger()->error('❌ MQTT ping failed: ' . $e->getMessage());
        }


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

        return view('admin.dashboard', compact(
            'usersCount',
            'orders',
            'ordersTotal',
            'ordersCompleted',
            'ordersPending'
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
