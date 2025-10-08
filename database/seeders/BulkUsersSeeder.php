<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Faker\Factory as FakerFactory;
use Carbon\Carbon;

class BulkUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = FakerFactory::create();

    $total = (int) env('BULK_USERS_COUNT', 20000);
    $chunkSize = (int) env('BULK_USERS_CHUNK', 1000); // insert users per chunk
        $now = Carbon::now()->toDateTimeString();

        $passwordHash = Hash::make('password');

        $batches = (int) ceil($total / $chunkSize);

        for ($b = 0; $b < $batches; $b++) {
            $users = [];
            $limit = ($b === $batches - 1) ? ($total - $b * $chunkSize) : $chunkSize;

            for ($i = 0; $i < $limit; $i++) {
                $name = $faker->name;
                // Use deterministic username portion with batch and index to avoid collisions
                $usernameBase = Str::slug(substr($name, 0, 20));
                $uniqueSuffix = ($b * $chunkSize) + $i + 1; // global incremental
                $username = $usernameBase . '-' . $uniqueSuffix;
                $email = $username . '@example.test';

                $users[] = [
                    'name' => $name,
                    'email' => $email,
                    'profile_link' => 'https://www.instagram.com/' . Str::slug($username),
                    'type' => 'user',
                    'points' => $faker->numberBetween(0, 1000),
                    'password' => $passwordHash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            \DB::table('users')->insert($users);

            $this->command->info("Inserted batch " . ($b + 1) . " of {$batches} (" . count($users) . " users)");
        }

        $this->command->info("Inserted total {$total} users.");
    }
}
