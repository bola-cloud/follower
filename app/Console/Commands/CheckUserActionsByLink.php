<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\User;

class CheckUserActionsByLink extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'resume:check-user-actions-by-link {--user=} {--link=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if a user has done/external actions on orders matching a given link (standalone test of exclusion query)';

    public function handle()
    {
        $userId = $this->option('user');
        $link = $this->option('link');

        if (empty($userId) || empty($link)) {
            $this->error('Please provide both --user and --link options.');
            $this->line('Example: php artisan resume:check-user-actions-by-link --user=15 --link="https://www.instagram.com/reel/DRqCdG0DDZS/?igsh=..."');
            return 1;
        }

        $user = User::find($userId);
        if (!$user) {
            $this->error("User not found: {$userId}");
            return 1;
        }

        $this->info("Checking actions for user {$user->id} on orders matching link: {$link}");

        // Normalize the link (same as ResumeOrderService)
        $normalized = trim($link);
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

        // Extract target id (last path segment)
        $targetId = strtolower(preg_replace('#^.*/#', '', $normalized));

        $this->line('--- Normalization ---');
        $this->line('original_link: ' . $link);
        $this->line('normalized: ' . $normalized);
        $this->line('target_id: ' . $targetId);

        // Query 1: Raw actions for this user with done/external status (unfiltered)
        $this->line("\n[Query 1 - RAW] List all done/external actions for user {$user->id}:");
        $rawActions = DB::table('actions as a1')
            ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
            ->where('a1.user_id', $user->id)
            ->whereIn('a1.status', ['done', 'external'])
            ->select('a1.user_id', 'a1.status', 'o1.id as order_id', 'o1.target_url', 'a1.created_at')
            ->orderBy('a1.created_at', 'desc')
            ->limit(100)
            ->get();

        $this->line("Found {$rawActions->count()} done/external actions");

        $matchCount = 0;
        foreach ($rawActions as $action) {
            // Normalize each URL to see if it matches
            $rawUrl = $action->target_url;
            $norm = trim($rawUrl);
            $norm = preg_replace('#^https?://#i', '', $norm);
            $norm = preg_replace('#^www\.#i', '', $norm);
            if (($pos = strpos($norm, '?')) !== false) { $norm = substr($norm, 0, $pos); }
            if (($pos = strpos($norm, '#')) !== false) { $norm = substr($norm, 0, $pos); }
            $norm = rtrim($norm, '/');
            $norm = strtolower($norm);
            $extractedId = strtolower(preg_replace('#^.*/#', '', $norm));

            $isMatch = ($extractedId === $targetId);
            if ($isMatch) {
                $matchCount++;
            }

            $matchLabel = $isMatch ? '[MATCH ✓]' : '[NO_MATCH]';
            $this->line(" - order: {$action->order_id} status: {$action->status} created: {$action->created_at}");
            $this->line("   url: {$rawUrl}");
            $this->line("   normalized: {$norm} -> extracted_id: {$extractedId} {$matchLabel}");
        }

        $this->line("\n--- Summary ---");
        $this->line("Total done/external actions: {$rawActions->count()}");
        $this->line("Matching target_id '{$targetId}': {$matchCount}");

        // Query 2: Test the SQL expression used in service
        $this->line("\n[Query 2 - SQL EXPRESSION TEST] Using the service's SQL extraction:");
        $likeBinding = "%/{$targetId}%";

        $sqlMatches = DB::table('actions as a1')
            ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
            ->where('a1.user_id', $user->id)
            ->whereIn('a1.status', ['done', 'external'])
            ->whereRaw("LOWER(o1.target_url) LIKE ?", [$likeBinding])
            ->select('a1.user_id', 'a1.status', 'o1.id as order_id', 'o1.target_url', 'a1.created_at', DB::raw("LOWER(o1.target_url) as extracted_by_sql"))
            ->orderBy('a1.created_at', 'desc')
            ->get();

        $this->line("SQL expression found {$sqlMatches->count()} matching actions:");
        foreach ($sqlMatches as $match) {
            $this->line(" - order: {$match->order_id} status: {$match->status} created: {$match->created_at}");
            $this->line("   url: {$match->target_url}");
            $this->line("   extracted_by_sql: {$match->extracted_by_sql}");
        }

        // Query 3: List all orders that match the target id
        $this->line("\n[Query 3] All orders matching target_id '{$targetId}':");
        $likeOrdersBinding = "%/{$targetId}%";
        $matchingOrders = DB::table('orders')
            ->whereRaw("LOWER(target_url) LIKE ?", [$likeOrdersBinding])
            ->select('id', 'user_id', 'target_url', 'created_at', DB::raw("LOWER(target_url) as extracted_by_sql"))
            ->orderBy('id', 'desc')
            ->limit(100)
            ->get();

        $this->line("Found {$matchingOrders->count()} orders with this target_id:");
        foreach ($matchingOrders as $ord) {
            $this->line(" - order_id: {$ord->id} owner: {$ord->user_id} created: {$ord->created_at}");
            $this->line("   url: {$ord->target_url}");
            $this->line("   extracted_by_sql: {$ord->extracted_by_sql}");
        }

        $this->info("\n✓ Done. User {$user->id} should be EXCLUDED if PHP match count > 0 and SQL match count > 0.");

        if ($matchCount > 0 && $sqlMatches->count() === 0) {
            $this->error("⚠ PROBLEM: PHP found {$matchCount} matches but SQL found 0 — SQL expression is broken!");
        } elseif ($matchCount > 0 && $sqlMatches->count() > 0) {
            $this->line("✓ Both PHP and SQL agree: user has {$matchCount} action(s) on this link and should be excluded.");
        } else {
            $this->line("✓ User has NO actions on this link — eligible.");
        }

        return 0;
    }
}
