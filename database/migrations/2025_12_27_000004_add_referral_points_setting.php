<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        // Best-effort: insert default referral_points setting if settings table exists
        try {
            DB::table('settings')->insertOrIgnore([
                ['key' => 'referral_points', 'value' => '50'],
            ]);
        } catch (\Throwable $e) {
            // ignore failures where table doesn't exist yet
        }
    }

    public function down()
    {
        try {
            DB::table('settings')->where('key', 'referral_points')->delete();
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
