<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('user_ad_watch_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->date('watch_date')->index();
            $table->integer('count')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'watch_date'], 'ux_user_watch_date');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_ad_watch_counts');
    }
};
