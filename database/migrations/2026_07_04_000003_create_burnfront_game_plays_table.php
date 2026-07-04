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
        Schema::create('burnfront_game_plays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mode');
            $table->string('difficulty')->nullable();
            $table->date('date')->nullable();
            $table->unsignedSmallInteger('rows');
            $table->unsignedSmallInteger('cols');
            $table->unsignedSmallInteger('breaks');
            $table->unsignedSmallInteger('spark');
            $table->json('clues');
            $table->json('shaded_cells');
            $table->json('moves');
            $table->unsignedInteger('time_ms')->nullable();
            $table->unsignedInteger('hints_used')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'mode', 'created_at']);
            $table->index(['user_id', 'date']);
            $table->index(['user_id', 'difficulty']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('burnfront_game_plays');
    }
};
