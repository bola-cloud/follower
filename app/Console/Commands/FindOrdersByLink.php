<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Order;

class FindOrdersByLink extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage: php artisan resume:find-orders --url="https://instagram.com/abc" --limit=20
     *
     * @var string
     */
    protected $signature = 'resume:find-orders {--url= : URL to search for} {--limit=10 : Max orders to show} {--by-id : Match by last path segment (reel/profile id) instead of full normalized URL}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find orders with the same normalized link and list users who have done/external actions on them';

    public function handle()
    {
        $url = $this->option('url');
        $limit = (int) $this->option('limit');

        if (empty($url)) {
            $this->error('Please provide --url option.');
            return 1;
        }

        // Normalize the URL similarly to ResumeOrderService
        $normalized = $this->normalizeUrl($url);
        $this->info("Searching orders matching normalized URL: {$normalized}");

        // Optionally match by last path segment (reel/profile id)
        $byId = (bool) $this->option('by-id');
        $targetId = strtolower(preg_replace('#^.*/#', '', $normalized));

        if ($byId) {
            $this->info("Searching orders matching target id: {$targetId}");
            $orders = DB::table('orders')
                ->select('id', 'target_url', 'user_id', 'created_at')
                ->whereRaw(
                    "LOWER(TRIM(SUBSTRING_INDEX(REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(TRIM(target_url), '\\\\?.*$', ''), '#.*$', ''), '^(https?://)?(www\\\\.)?', ''), '/', -1))) = ?",
                    [$targetId]
                )
                ->limit($limit)
                ->get();
        } else {
            // Find orders with same normalized target_url
            $orders = DB::table('orders')
                ->select('id', 'target_url', 'user_id', 'created_at')
                ->whereRaw(
                    "LOWER(TRIM(TRAILING '/' FROM REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(TRIM(target_url), '\\\\?.*$', ''), '#.*$', ''), '^(https?://)?(www\\\\.)?', '')))=?",
                    [$normalized]
                )
                ->limit($limit)
                ->get();
            }

            if ($orders->isEmpty()) {
            $this->info('No matching orders found.');
            return 0;
        }

        foreach ($orders as $o) {
            $this->line("Order {$o->id} (owner: {$o->user_id}) - {$o->target_url}");

            // Find users who have done or external on this order
            $users = DB::table('actions')
                ->where('order_id', $o->id)
                ->whereIn('status', ['done', 'external'])
                ->join('users', 'actions.user_id', '=', 'users.id')
                ->select('users.id as user_id', 'users.profile_link', 'actions.status', 'actions.created_at')
                ->limit(200)
                ->get();

            if ($users->isEmpty()) {
                $this->line('  No users with done/external actions for this order.');
                continue;
            }

            $this->table(['user_id','profile_link','status','action_created_at'], $users->map(function($u){
                return [(int)$u->user_id, $u->profile_link, $u->status, (string)$u->created_at];
            })->toArray());
        }

        return 0;
    }

    private function normalizeUrl(string $url): string
    {
        $normalized = trim($url);
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
        return $normalized;
    }
}
