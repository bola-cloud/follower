<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('invitation_code', 32)->nullable()->unique()->after('remember_token');
        });

        // Backfill existing users with a unique code
        $users = DB::table('users')->select('id')->get();
        foreach ($users as $u) {
            $code = null;
            do {
                $code = strtoupper(Str::random(8));
            } while (DB::table('users')->where('invitation_code', $code)->exists());

            DB::table('users')->where('id', $u->id)->update(['invitation_code' => $code]);
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['invitation_code']);
            $table->dropColumn('invitation_code');
        });
    }
};
