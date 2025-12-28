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
use Google\Auth\Credentials\ServiceAccountCredentials;

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

        try {
            // Check if Google Auth library exists
            if (!class_exists('Google\Auth\Credentials\ServiceAccountCredentials')) {
                throw new \Exception("Google Auth library not found. Please run: composer require google/auth");
            }

            // Get credentials path from .env
            $credentialsPath = base_path(env('FIREBASE_CREDENTIALS', 'storage/app/firebase-service-account.json'));

            if (!file_exists($credentialsPath)) {
                throw new \Exception("Firebase Service Account file not found at: $credentialsPath");
            }

            // Generate Access Token
            $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
            $credentials = new ServiceAccountCredentials($scopes, $credentialsPath);
            $token = $credentials->fetchAuthToken(Http::class);

            if (!isset($token['access_token'])) {
                throw new \Exception("Failed to generate Firebase access token.");
            }

            $accessToken = $token['access_token'];
            $projectId = 'egfollow-d78d1'; // From your google-services.json

            // HTTP v1 Payload
            $payload = [
                'message' => [
                    'topic' => 'all', // Ensure logic matches topic vs token
                    'notification' => [
                        'title' => $record->title,
                        'body' => $record->body,
                    ],
                    'data' => $record->data ?? new \stdClass(),
                ]
            ];

            // Send Request
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ])->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", $payload);

            if ($response->successful()) {
                $record->status = 'sent';
                $record->sent_at = now();
                $record->save();
            } else {
                $record->status = 'failed';
                $record->save();
                Log::error('FCM HTTPv1 Send Failed', ['response' => $response->body()]);
            }

        } catch (\Throwable $e) {
            $record->status = 'failed';
            $record->save();
            Log::error('FCM Job Exception', ['error' => $e->getMessage()]);
        }
    }
}
