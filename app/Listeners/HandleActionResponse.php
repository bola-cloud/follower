<?php
// app/Listeners/HandleActionResponse.php
namespace App\Listeners;

use Illuminate\Support\Facades\DB;

class HandleActionResponse
{
    public function handle($payload)
    {
        DB::table('actions')
            ->where('order_id', $payload['order_id'])
            ->where('user_id', $payload['user_id'])
            ->update([
                'status' => $payload['status'] === 'success' ? 'done' : 'external',
                'performed_at' => now(),
            ]);
    }
}
