<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Small example seeder (kept for backwards compatibility)
        $this->call(UsersTableSeeder::class);
        $this->call(WebsiteUrlSettingSeeder::class);

        // Bulk users seeder (20k users). Disabled by default to avoid accidental long runs.
        // To run it locally set APP_ENV=local and RUN_BULK_SEED=true or run the seeder directly:
        // php artisan db:seed --class=Database\\Seeders\\BulkUsersSeeder
        if (env('APP_ENV') === 'local' && env('RUN_BULK_SEED', false)) {
            $this->call(BulkUsersSeeder::class);
        }
    }
}
