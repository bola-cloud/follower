<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\User;
use App\Services\ResumeOrderService;

class CheckUsersEligibility extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage: php artisan resume:check-users --order=123 --users=1,2,3
     *
     * @var string
     */
    protected $signature = 'resume:check-users {--order= : Order ID to check against} {--users= : Comma separated user ids} {--method=batch : Eligibility method: "batch" (batchCheckEligibility) or "single" (checkUserEligibility)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check eligibility of a list of user IDs for a given order using ResumeOrderService';

    public function handle(ResumeOrderService $resumeService)
    {
        $orderId = $this->option('order');
        $usersOpt = $this->option('users');

        if (empty($orderId) || empty($usersOpt)) {
            $this->error('Please provide both --order and --users options. Example: --order=123 --users=1,2,3');
            return 1;
        }

        $order = Order::find($orderId);
        if (!$order) {
            $this->error("Order ID {$orderId} not found.");
            return 1;
        }

        $userIds = array_filter(array_map('trim', explode(',', $usersOpt)), function($v) { return $v !== ''; });
        $userIds = array_map('intval', $userIds);

        if (empty($userIds)) {
            $this->error('No valid user IDs provided.');
            return 1;
        }

        $this->info("Checking eligibility for order {$order->id} against users: " . implode(',', $userIds));

        $method = strtolower($this->option('method') ?? 'batch');

        $headers = ['user_id', 'eligible', 'method', 'note'];
        $rows = [];

        if ($method === 'single') {
            // Call checkUserEligibility for each user (may be heavier)
            foreach ($userIds as $uid) {
                $user = User::find($uid);
                if (!$user) {
                    $rows[] = [$uid, 'NO', 'single', 'user-not-found'];
                    continue;
                }
                try {
                    $ok = $resumeService->checkUserEligibility($order, $user);
                    $rows[] = [$uid, $ok ? 'YES' : 'NO', 'single', $ok ? '' : 'excluded or pending/done'];
                } catch (\Throwable $e) {
                    $rows[] = [$uid, 'ERROR', 'single', $e->getMessage()];
                }
            }
            // Summary
            $eligibleCount = count(array_filter($rows, function($r){ return $r[1] === 'YES'; }));
            $this->table($headers, $rows);
            $this->info('Eligible count: ' . $eligibleCount);
        } else {
            // Default: batch method
            $eligible = $resumeService->batchCheckEligibility($order, $userIds);
            $eligibleMap = array_flip($eligible);

            foreach ($userIds as $uid) {
                $isEligible = isset($eligibleMap[$uid]);
                $rows[] = [$uid, $isEligible ? 'YES' : 'NO', 'batch', $isEligible ? '' : 'excluded or pending/done'];
            }

            $this->table($headers, $rows);
            $this->info('Eligible count: ' . count($eligible));
            $this->info('Eligible IDs: ' . (empty($eligible) ? 'none' : implode(',', $eligible)));
        }

        return 0;
    }
}
