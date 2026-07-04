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
        Schema::table('burnfront_daily_scores', function (Blueprint $table) {
            $table->unsignedInteger('hints_used')->default(0)->after('time_ms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('burnfront_daily_scores', function (Blueprint $table) {
            $table->dropColumn('hints_used');
        });
    }
};
