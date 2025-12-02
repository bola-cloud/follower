<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Insert default setting if not exists
        try {
            DB::table('settings')->insertOrIgnore([
                ['key' => 'points_per_ads', 'value' => '1'],
            ]);
        } catch (\Throwable $e) {
            // best-effort; do not fail migration on missing table in some edge cases
            
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        try {
            DB::table('settings')->where('key', 'points_per_ads')->delete();
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
