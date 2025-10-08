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
        Schema::table('orders', function (Blueprint $table) {
            // Add nullable string columns for external media id and user pk
            $table->string('mediaId')->nullable()->after('target_url');
            $table->string('userPk')->nullable()->after('mediaId');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'userPk')) {
                $table->dropColumn('userPk');
            }
            if (Schema::hasColumn('orders', 'mediaId')) {
                $table->dropColumn('mediaId');
            }
        });
    }
};
