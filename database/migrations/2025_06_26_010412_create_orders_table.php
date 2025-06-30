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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['follow', 'like']);
            $table->unsignedInteger('total_count');
            $table->unsignedInteger('done_count')->default(0);
            $table->integer('cost')->default(0);
            $table->enum('status', ['active', 'paused', 'completed'])->default('active');
            $table->text('target_url'); // Instagram profile or post link
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Creator of the order
            $table->timestamps();

            // Index for filtering
            $table->index('target_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
