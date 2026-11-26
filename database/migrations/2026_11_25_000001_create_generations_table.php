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
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider_id')->nullable()->unique();
            $table->string('key_label');
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_urls');
            $table->string('status');
            $table->unsignedBigInteger('estimated_min_picos');
            $table->unsignedBigInteger('estimated_max_picos');
            $table->unsignedBigInteger('provider_cost_picos')->nullable();
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('image_expires_at')->nullable();
            $table->text('image_url')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('attention_reason')->nullable();
            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->boolean('acceptance_uncertain')->default(false);
            $table->unsignedTinyInteger('recovery_step')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'next_check_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
