<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->enum('plan', ['free', 'starter', 'pro', 'enterprise'])->default('free');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 10)->default('GHS');
            $table->string('paystack_subscription_code')->nullable();
            $table->string('paystack_customer_code')->nullable();
            $table->string('paystack_plan_code')->nullable();
            $table->enum('status', ['active', 'cancelled', 'past_due', 'trialing'])->default('active');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
