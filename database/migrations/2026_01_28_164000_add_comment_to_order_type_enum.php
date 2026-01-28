<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE orders MODIFY COLUMN type ENUM('follow', 'like', 'comment') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reverting might be dangerous if there are 'comment' orders, but strictly speaking keeping 'comment' is fine if we roll back code,
        // or we can strictly revert. Since data loss is risky, I'll just revert the definition.
        // However, if there are 'comment' rows, this will fail.
        // For safety in this environment, I'll leave it as is or try to modify back if empty.
        // A simple modify back is standard for 'down', acknowledging data restrictions.
        DB::statement("ALTER TABLE orders MODIFY COLUMN type ENUM('follow', 'like') NOT NULL");
    }
};
