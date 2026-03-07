<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['plan_upgrade', 'discount', 'trial_extension'])->default('plan_upgrade');
            $table->string('plan')->nullable(); // target plan for plan_upgrade type
            $table->integer('plan_duration_days')->default(30); // how long the plan lasts
            $table->integer('discount_percent')->nullable(); // for discount type
            $table->integer('trial_days')->nullable(); // for trial_extension type
            $table->integer('max_uses')->default(1); // 0 = unlimited
            $table->integer('times_used')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['code', 'is_active']);
            $table->index('expires_at');
        });

        // Track which users redeemed which codes
        Schema::create('promo_code_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->string('previous_plan')->nullable();
            $table->string('new_plan')->nullable();
            $table->timestamp('plan_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['promo_code_id', 'user_id']); // one redemption per user per code
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_redemptions');
        Schema::dropIfExists('promo_codes');
    }
};
