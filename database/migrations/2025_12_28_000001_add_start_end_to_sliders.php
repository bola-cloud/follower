<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('sliders', function (Blueprint $table) {
            $table->dateTime('start_at')->nullable()->after('is_active');
            $table->dateTime('end_at')->nullable()->after('start_at');
        });
    }

    public function down()
    {
        Schema::table('sliders', function (Blueprint $table) {
            $table->dropColumn(['start_at','end_at']);
        });
    }
};
