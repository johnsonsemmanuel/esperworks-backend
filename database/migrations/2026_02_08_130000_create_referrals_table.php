<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add referral_code and referred_by to users
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'referral_code')) {
                $table->string('referral_code', 20)->nullable()->unique()->after('role');
            }
            if (!Schema::hasColumn('users', 'referred_by')) {
                $table->unsignedBigInteger('referred_by')->nullable()->after('referral_code');
            }
            if (!Schema::hasColumn('users', 'referral_bonus_features')) {
                $table->json('referral_bonus_features')->nullable()->after('referred_by');
            }
        });

        // Referrals tracking table
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('referrer_id');
            $table->unsignedBigInteger('referred_id');
            $table->string('status')->default('pending'); // pending, active, rewarded
            $table->string('reward_type')->nullable(); // feature_unlock, plan_upgrade, credit
            $table->string('reward_detail')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();

            $table->foreign('referrer_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('referred_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['referrer_id', 'referred_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['referral_code', 'referred_by', 'referral_bonus_features']);
        });
    }
};
