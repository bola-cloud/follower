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
        Schema::create('actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['follow', 'like']);
            $table->enum('status', ['pending', 'done', 'external'])->default('pending');
            $table->timestamp('performed_at')->nullable();
            $table->timestamps();

            // Uniqueness per user per target
            $table->unique(['user_id', 'order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actions');
    }
};
