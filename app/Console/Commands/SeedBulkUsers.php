<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SeedBulkUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Allows optional --count and --chunk flags
     */
    protected $signature = 'seed:bulk-users {--count=20000} {--chunk=1000} {--force}';

    /**
     * The console command description.
     */
    protected $description = 'Seed bulk users efficiently (defaults to 20,000). Use --force to bypass environment check.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = (int) $this->option('count');
        $chunk = (int) $this->option('chunk');

        if (app()->environment(['production']) && !$this->option('force')) {
            $this->error('Refusing to run bulk seeder in production. Use --force to override.');
            return 1;
        }

        $this->info("Seeding {$count} users in chunks of {$chunk}...");

        // Set environment variable for seeder to pick up
        putenv('BULK_USERS_COUNT=' . $count);
        putenv('BULK_USERS_CHUNK=' . $chunk);

        // Call seeder directly
        Artisan::call('db:seed', [
            '--class' => '\\Database\\Seeders\\BulkUsersSeeder'
        ]);

        $this->info('Bulk seeder finished.');

        return 0;
    }
}
