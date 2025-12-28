<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\PushNotification;
use Illuminate\Support\Facades\DB;

class SendFcmNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $notificationId;

    public function __construct($notificationId)
    {
        $this->notificationId = $notificationId;
    }

    public function handle()
    {
        $record = PushNotification::find($this->notificationId);
        if (!$record)
            return;

        // Read server key from config
        $serverKey = config('services.fcm.key');
        if (!$serverKey) {
            $record->status = 'failed';
            $record->save();
            Log::warning('SendFcmNotification: No FCM server key configured');
            return;
        }

        $payload = [
            'to' => '/topics/all',
            'notification' => [
                'title' => $record->title,
                'body' => $record->body,
            ],
            'data' => $record->data ?? new \stdClass(),
        ];

        try {
            $res = Http::withHeaders([
                'Authorization' => 'key=' . $serverKey,
                'Content-Type' => 'application/json',
            ])->post('https://fcm.googleapis.com/fcm/send', $payload);

            if ($res->successful()) {
                $record->status = 'sent';
                $record->sent_at = now();
                $record->save();
            } else {
                $record->status = 'failed';
                $record->save();
                Log::error('SendFcmNotification failed', ['response' => $res->body()]);
            }
        } catch (\Throwable $e) {
            $record->status = 'failed';
            $record->save();
            Log::error('SendFcmNotification exception', ['error' => $e->getMessage()]);
        }
    }
}
