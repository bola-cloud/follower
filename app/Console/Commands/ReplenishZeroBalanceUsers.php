<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReplenishZeroBalanceUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'points:replenish-zero-balance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Replenish points for users with zero balance whose timer has expired';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting points replenishment check...');

        try {
            // Get the amount of points to add from settings
            $addedPoints = setting('added_points', 50);

            // Log basic info
            Log::info('[ReplenishZeroBalanceUsers] Starting run', [
                'added_points' => $addedPoints
            ]);

            // Find users who have 0 points AND their timer has passed (timer <= now)
            // We use 'chunkById' to handle large datasets efficiently
            $count = 0;

            // Query: points = 0 AND (timer is NOT NULL AND timer <= now())
            // If timer is NULL, it usually means they haven't started a wait period or logic handled elsewhere.
            // Based on AddPointsToUser, we respect the timer.
            // If timer is in the past, they are eligible.

            User::where('points', 0)
                ->whereNotNull('timer')
                ->where('timer', '<=', now())
                ->chunkById(100, function ($users) use ($addedPoints, &$count) {
                    foreach ($users as $user) {
                        try {
                            DB::transaction(function () use ($user, $addedPoints) {
                                // Lock row to ensure we don't double-process if job is also running
                                $freshUser = User::where('id', $user->id)->lockForUpdate()->first();

                                // Double check conditions inside lock
                                if (!$freshUser || $freshUser->points > 0) {
                                    return;
                                }

                                // Additional check: if timer is somehow in future (race condition?), skip
                                if ($freshUser->timer && $freshUser->timer->isFuture()) {
                                    return;
                                }

                                $freshUser->increment('points', $addedPoints);
                                // Optional: Reset timer? Usually timer is for the "wait", now the wait is over.
                                // We might want to clear it or leave it as past timestamp.
                                // AddPointsToUser doesn't clear it, just increments.
                                // But if we don't change the state (points > 0), this loop would run forever.
                                // Since we increment points, points will be > 0, so query won't pick them up next time.
    
                                $freshUser->save();
                            });

                            $this->info("Refilled user {$user->id} with {$addedPoints} points.");
                            Log::info('[ReplenishZeroBalanceUsers] Refilled user', ['user_id' => $user->id]);
                            $count++;
                        } catch (\Throwable $e) {
                            $this->error("Failed to refill user {$user->id}: " . $e->getMessage());
                            Log::error('[ReplenishZeroBalanceUsers] Failed to refill user', [
                                'user_id' => $user->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                });

            $this->info("Completed. Refilled {$count} users.");
            Log::info('[ReplenishZeroBalanceUsers] Completed', ['count' => $count]);

        } catch (\Throwable $e) {
            $this->error('Fatal error: ' . $e->getMessage());
            Log::error('[ReplenishZeroBalanceUsers] Fatal error', ['error' => $e->getMessage()]);
            return 1;
        }

        return 0;
    }
}
