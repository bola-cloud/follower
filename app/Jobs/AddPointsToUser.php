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

            // Use try-catch for setting retrieval in case of DB issues
            try {
                $addedPoints = setting('added_points', 50);
            } catch (\Throwable $e) {
                \Log::warning('AddPointsToUser: Failed to fetch setting, using fallback', ['error' => $e->getMessage()]);
                $addedPoints = 50; // Safe fallback
            }

            $user->increment('points', $addedPoints);

            \Log::info("AddPointsToUser: Added points", ['user_id' => $user->id, 'points' => $addedPoints]);
        } catch (\Throwable $e) {
            \Log::error('AddPointsToUser: Job failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Don't rethrow - this prevents infinite retries
            // The job will be marked as completed even if it failed
        }
    }
}
