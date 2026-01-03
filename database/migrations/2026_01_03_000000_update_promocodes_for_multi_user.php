<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('promocodes', function (Blueprint $table) {
            $table->integer('max_uses')->default(1)->after('points');
            $table->integer('uses_count')->default(0)->after('max_uses');
            $table->dropColumn('activated_at'); // Moved to pivot table
        });

        Schema::create('promocode_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promocode_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('used_at')->useCurrent();

            // Ensure a user can only use a code once
            $table->unique(['promocode_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promocode_user');

        Schema::table('promocodes', function (Blueprint $table) {
            $table->timestamp('activated_at')->nullable();
            $table->dropColumn('uses_count');
            $table->dropColumn('max_uses');
        });
    }
};
