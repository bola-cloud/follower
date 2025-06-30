<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create the table
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['follow', 'like']);
            $table->unsignedInteger('total_count');
            $table->unsignedInteger('done_count')->default(0);
            $table->integer('cost')->default(0);
            $table->enum('status', ['active', 'paused', 'completed'])->default('active');
            $table->string('target_url', 2048); // We'll index it manually below
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });

        // Add a safe index to the target_url column
        DB::statement('ALTER TABLE orders ADD INDEX orders_target_url_index (target_url(191))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
