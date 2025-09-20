<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Str;

class CreateLoadTestUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder creates 1000 users in chunks to mirror the test harness used
     * by `SimulateOrderAndResponses`. Each user will have `type = 'user'` and
     * `points = 10` so they match responders used in load tests.
     *
     * It prints progress to the console and writes the created user IDs to
     * `storage/logs/loadtest_users_{ts}.json` for later consumption.
     */
    public function run()
    {
        $total = 1000;
        $chunk = 200;
        $createdIds = [];

        $this->command->info("Creating {$total} load-test users in chunks of {$chunk}...");

        for ($i = 0; $i < $total; $i += $chunk) {
            $c = min($chunk, $total - $i);
            $users = User::factory()->count($c)->create([
                'type' => 'user',
                'points' => 10,
            ]);

            $ids = $users->pluck('id')->toArray();
            $createdIds = array_merge($createdIds, $ids);
            $this->command->info("Created {$c} users (total " . count($createdIds) . ")");
        }

        $ts = time();
        $exportPath = storage_path("logs/loadtest_users_{$ts}.json");
        file_put_contents($exportPath, json_encode([
            'ts' => $ts,
            'total' => $total,
            'created_count' => count($createdIds),
            'user_ids' => $createdIds,
        ], JSON_PRETTY_PRINT));

        $this->command->info('Finished creating users. IDs exported to: ' . $exportPath);
    }
}
