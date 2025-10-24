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
        $this->info('Dispatching InsertAndPublishForActiveDashboardUsers job...');
        InsertAndPublishForActiveDashboardUsers::dispatch();
        $this->info('Job dispatched.');
        return 0;
    }
}
