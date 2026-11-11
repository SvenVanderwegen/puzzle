<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A daily solved as a guest and then saved to an account on sign-in
     * counts for the streak, but its clock never ran on an account, so it
     * stays off the leaderboard (ranked = false).
     */
    public function up(): void
    {
        Schema::table('burnfront_daily_scores', function (Blueprint $table) {
            $table->boolean('ranked')->default(true)->after('hints_used');
        });
    }

    public function down(): void
    {
        Schema::table('burnfront_daily_scores', function (Blueprint $table) {
            $table->dropColumn('ranked');
        });
    }
};
