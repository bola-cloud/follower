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
            $table->unsignedInteger('total_count');  // total likes or follows
            $table->unsignedInteger('done_count')->default(0); // completed likes or follows
            $table->integer('cost')->default(0); // remaining likes or follows
            $table->enum('status', ['active', 'paused', 'completed'])->default('active');
            $table->text('target_url')->nullable(); // URL to target for likes or follows
            $table->unsignedBigInteger('user_id'); // user who created the order
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
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
