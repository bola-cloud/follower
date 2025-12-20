<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('apks', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->string('file_name');
            $table->string('file_path');
            $table->integer('file_size')->default(0); // in bytes
            $table->string('play_store_url')->nullable();
            $table->enum('status', ['live', 'pending', 'failed', 'archived'])->default('pending');
            $table->integer('download_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apks');
    }
};
