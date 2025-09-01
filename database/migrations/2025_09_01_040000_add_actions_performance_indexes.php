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
        Schema::table('actions', function (Blueprint $table) {
            // Add index for faster counting of done actions
            $table->index(['order_id', 'status'], 'actions_order_status_index');

            // Add index for faster user eligibility checks
            $table->index(['user_id', 'status'], 'actions_user_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->dropIndex('actions_order_status_index');
            $table->dropIndex('actions_user_status_index');
        });
    }
};
