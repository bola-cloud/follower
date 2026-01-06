<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AddPointsToUser implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $user = User::find($this->userId);

            if (!$user) {
                \Log::warning("AddPointsToUser: User not found", ['user_id' => $this->userId]);
                return;
            }

            // 1. Check if user already has points (Refill logic)
            // If the user managed to get points from somewhere else while waiting, we abort.
            if ($user->points > 0) {
                \Log::info("AddPointsToUser: User already has points ({$user->points}), skipping refill.", ['user_id' => $user->id]);
                return;
            }

            // 2. Strict Timer Check
            // If the user has a timer set in the future, we must wait.
            // Even if the queue runs this job now, we push it back.
            // We use timestamps to be timezone-agnostic (absolute time comparison).
            if ($user->timer && now()->timestamp < $user->timer->timestamp) {
                // Calculate seconds remaining
                $seconds = $user->timer->timestamp - now()->timestamp + 5; // +5s buffer
                \Log::info("AddPointsToUser: Too early, releasing back to queue.", [
                    'user_id' => $user->id,
                    'wait_seconds' => $seconds,
                    'timer_ts' => $user->timer->timestamp,
                    'now_ts' => now()->timestamp
                ]);

                // Release the job back to the queue to run after $seconds
                $this->release($seconds);
                return;
            }

            // 3. Add Points
            try {
                // Use 'points_add_delay' value or 'added_points' setting? Assuming 'added_points'
                $addedPoints = setting('added_points', 50);
            } catch (\Throwable $e) {
                \Log::warning('AddPointsToUser: Failed to fetch setting, using fallback', ['error' => $e->getMessage()]);
                $addedPoints = 50;
            }

            \DB::transaction(function () use ($user, $addedPoints) {
                // Lock row to prevent race conditions
                $user = User::where('id', $user->id)->lockForUpdate()->first();

                // Double check inside lock
                if ($user->points > 0)
                    return;

                $user->increment('points', $addedPoints);
                // We do NOT toggle registration_points_awarded anymore as this is a refill job
                // $user->registration_points_awarded = true; 
                $user->save();
            });

            \Log::info("AddPointsToUser: Points added successfully.", ['user_id' => $user->id, 'points' => $addedPoints]);

        } catch (\Throwable $e) {
            \Log::error('AddPointsToUser: Job failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // If it fails, we let it retry naturally (depending on queue config),
            // or we could manually release it. Default queue retry is usually safely handled since we have the flag.
        }
    }
}
