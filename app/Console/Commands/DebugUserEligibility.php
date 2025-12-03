<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\User;
use App\Services\ResumeOrderService;

class DebugUserEligibility extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resume:debug-eligibility {--order=} {--user=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug eligibility check for a single user and order with detailed query logs';

    public function handle()
    {
        $orderId = $this->option('order');
        $userId = $this->option('user');

        if (empty($orderId) || empty($userId)) {
            $this->error('Please provide both --order and --user options.');
            return 1;
        }

        $order = Order::find($orderId);
        $user = User::find($userId);

        if (!$order) {
            $this->error("Order not found: {$orderId}");
            return 1;
        }
        if (!$user) {
            $this->error("User not found: {$userId}");
            return 1;
        }

        $this->info("Debugging eligibility for user {$user->id} on order {$order->id}");

        // Replicate normalizeUrl logic (same as ResumeOrderService)
        $normalized = trim($order->target_url);
        $normalized = preg_replace('#^https?://#i', '', $normalized);
        $normalized = preg_replace('#^www\.#i', '', $normalized);
        if (($pos = strpos($normalized, '?')) !== false) {
            $normalized = substr($normalized, 0, $pos);
        }
        if (($pos = strpos($normalized, '#')) !== false) {
            $normalized = substr($normalized, 0, $pos);
        }
        $normalized = rtrim($normalized, '/');
        $normalized = strtolower($normalized);

        // Extract last path segment (target id)
        $targetId = strtolower(preg_replace('#^.*/#', '', $normalized));

        $this->line('--- Normalization ---');
        $this->line('original_url: ' . $order->target_url);
        $this->line('normalized_url: ' . $normalized);
        $this->line('target_id: ' . $targetId);

        Log::info('[resume:debug-eligibility] start', ['order_id'=>$order->id, 'user_id'=>$user->id, 'normalized'=>$normalized, 'target_id'=>$targetId]);

        // 1) Check for existing action for this user on this order
        $sql1 = "SELECT status, created_at FROM actions WHERE order_id = ? AND user_id = ?";
        $this->line('\n[Query 1] ' . $sql1 . ' -- bindings: [' . $order->id . ', ' . $user->id . ']');
        $res1 = DB::select($sql1, [$order->id, $user->id]);
        $this->line('Result: ' . json_encode($res1));
        Log::info('[resume:debug-eligibility] query1', ['sql'=>$sql1, 'bindings'=>[$order->id,$user->id], 'result'=>$res1]);

        // 2) Counts for this order
        $sql2 = "SELECT SUM(status='done') as done_count, SUM(status='pending' AND created_at >= ?) as recent_pending_count FROM actions WHERE order_id = ?";
        $recentTime = now()->subMinutes(30)->toDateTimeString();
        $this->line('\n[Query 2] ' . $sql2 . ' -- bindings: [' . $recentTime . ', ' . $order->id . ']');
        try {
            $res2 = DB::select($sql2, [$recentTime, $order->id]);
        } catch (\Throwable $e) {
            // some MySQL versions may not accept SUM(condition) syntax; run separate counts
            $this->line('Query 2 failed with SUM(condition) syntax, falling back to separate counts');
            $doneCount = DB::table('actions')->where('order_id', $order->id)->where('status','done')->count();
            $recentPendingCount = DB::table('actions')->where('order_id', $order->id)->where('status','pending')->where('created_at','>=', $recentTime)->count();
            $res2 = [(object)['done_count'=>$doneCount, 'recent_pending_count'=>$recentPendingCount]];
        }
        $this->line('Result: ' . json_encode($res2));
        Log::info('[resume:debug-eligibility] query2', ['sql'=>$sql2, 'bindings'=>[$recentTime,$order->id], 'result'=>$res2]);

        // 3) Pending users for this order (sample)
        $sql3 = "SELECT user_id, status, created_at FROM actions WHERE order_id = ? AND status = 'pending' LIMIT 200";
        $this->line('\n[Query 3] ' . $sql3 . ' -- bindings: [' . $order->id . ']');
        $res3 = DB::select($sql3, [$order->id]);
        $this->line('Result count: ' . count($res3));
        Log::info('[resume:debug-eligibility] query3', ['sql'=>$sql3,'bindings'=>[$order->id],'count'=>count($res3)]);

        // 4) Does this user have done/external actions on OTHER orders with the same target id?
        $sql4 = "SELECT a1.user_id, a1.status, o1.id as other_order_id, o1.target_url as other_url, a1.created_at FROM actions a1 JOIN orders o1 ON a1.order_id = o1.id WHERE a1.user_id = ? AND a1.status IN ('done','external') AND LOWER(TRIM(SUBSTRING_INDEX(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(TRIM(o1.target_url), '\\\\?.*$', ''), '#.*$', ''), '^(https?://)?(www\\\\.)?', ''), '/', -1))) = ? AND o1.id != ?";
        $this->line('\n[Query 4] ' . $sql4 . ' -- bindings: [' . $user->id . ', ' . $targetId . ', ' . $order->id . ']');
        $res4 = DB::select($sql4, [$user->id, $targetId, $order->id]);
        $this->line('Found ' . count($res4) . ' matching done/external actions for this user on other orders');
        foreach ($res4 as $row) {
            $this->line(" - order: {$row->other_order_id} status: {$row->status} url: {$row->other_url} at: {$row->created_at}");
        }
        Log::info('[resume:debug-eligibility] query4', ['sql'=>$sql4, 'bindings'=>[$user->id,$targetId,$order->id], 'result'=>$res4]);

        // 5) Does user's profile_link equal the target username?
        $extractedUsername = strtolower(preg_replace('#^.*/#', '', $normalized));
        $sql5 = "SELECT id, profile_link, LOWER(TRIM(profile_link)) as profile_link_normalized FROM users WHERE id = ?";
        $this->line('\n[Query 5] ' . $sql5 . ' -- bindings: [' . $user->id . ']');
        $res5 = DB::select($sql5, [$user->id]);
        $this->line('Result: ' . json_encode($res5));
        $this->line('Extracted target username: ' . $extractedUsername);
        if (!empty($res5)) {
            $pl = $res5[0]->profile_link_normalized ?? null;
            $this->line('User profile_link normalized: ' . ($pl ?? 'NULL'));
            $this->line('profile_link == target_username ? ' . (($pl === $extractedUsername) ? 'YES' : 'NO'));
        }
        Log::info('[resume:debug-eligibility] query5', ['sql'=>$sql5,'bindings'=>[$user->id],'result'=>$res5,'extracted'=>$extractedUsername]);

        // 6) List all orders that match the target id (sample)
        $sql6 = "SELECT id, user_id, target_url, created_at FROM orders WHERE LOWER(TRIM(SUBSTRING_INDEX(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(TRIM(target_url), '\\\\?.*$', ''), '#.*$', ''), '^(https?://)?(www\\\\.)?', ''), '/', -1))) = ? ORDER BY id DESC LIMIT 200";
        $this->line('\n[Query 6] ' . $sql6 . ' -- bindings: [' . $targetId . ']');
        $res6 = DB::select($sql6, [$targetId]);
        $this->line('Found ' . count($res6) . ' orders with same target id');
        foreach ($res6 as $o) {
            $this->line(" - order_id: {$o->id} owner: {$o->user_id} url: {$o->target_url} created: {$o->created_at}");
        }
        Log::info('[resume:debug-eligibility] query6', ['sql'=>$sql6,'bindings'=>[$targetId],'count'=>count($res6)]);

        // 7) Final: call service checkUserEligibility to compare
        try {
            $service = app(ResumeOrderService::class);
            $decision = $service->checkUserEligibility($order, $user) ? 'ELIGIBLE' : 'NOT ELIGIBLE';
            $this->line('\n[Final Decision] ResumeOrderService::checkUserEligibility => ' . $decision);
            Log::info('[resume:debug-eligibility] final_decision', ['order_id'=>$order->id,'user_id'=>$user->id,'decision'=>$decision]);
        } catch (\Throwable $e) {
            $this->error('Failed to call ResumeOrderService::checkUserEligibility: ' . $e->getMessage());
            Log::error('[resume:debug-eligibility] service_call_failed', ['error'=>$e->getMessage()]);
        }

        $this->info('\nDone. Logs written to laravel log and console output above.');
        return 0;
    }
}
