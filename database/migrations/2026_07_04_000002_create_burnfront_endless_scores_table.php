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
        Schema::create('burnfront_endless_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('difficulty');
            $table->unsignedInteger('solved_count')->default(0);
            $table->unsignedInteger('best_time_ms')->nullable();
            $table->timestamp('last_solved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'difficulty']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('burnfront_endless_scores');
    }
};
