<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\InsertAndPublishForActiveDashboardUsers;

class ResumeActiveOrders extends Command
{
    protected $signature = 'orders:resume-active {--once : Dispatch once and exit}';
    protected $description = 'Dispatch coordinator job to resume/publish orders for active dashboard users';

    public function handle()
    {
        $this->info('[ResumeActiveOrders] Running InsertAndPublishForActiveDashboardUsers synchronously...');

        // Run the job synchronously instead of dispatching to queue so the scheduler
        // sees immediate output. For production with queue workers, use ::dispatch()
        // but ensure the queue is being processed.
        $job = new InsertAndPublishForActiveDashboardUsers();
        $job->handle();

        $this->info('[ResumeActiveOrders] Job completed.');
        return 0;
    }
}
