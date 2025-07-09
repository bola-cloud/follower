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
        $user = User::find($this->userId);

        if (!$user) {
            \Log::warning("AddPointsToUser: User not found: {$this->userId}");
            return;
        }

        $addedPoints = setting('added_points', 50); // fallback if not found

        $user->increment('points', $addedPoints);

        \Log::info("Added {$addedPoints} points to user #{$user->id}");
    }
}
