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
    protected $signature = 'resume:check-users {--order= : Order ID to check against} {--users= : Comma separated user ids}';

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

        // Use batchCheckEligibility to compute eligible user ids among candidates
        $eligible = $resumeService->batchCheckEligibility($order, $userIds);
        $eligibleMap = array_flip($eligible);

        $headers = ['user_id', 'eligible', 'reason_sample'];
        $rows = [];

        foreach ($userIds as $uid) {
            $isEligible = isset($eligibleMap[$uid]);
            $rows[] = [
                $uid,
                $isEligible ? 'YES' : 'NO',
                $isEligible ? '' : 'excluded or pending/done'
            ];
        }

        $this->table($headers, $rows);

        // Also print a short summary
        $this->info('Eligible count: ' . count($eligible));
        $this->info('Eligible IDs: ' . (empty($eligible) ? 'none' : implode(',', $eligible)));

        return 0;
    }
}
