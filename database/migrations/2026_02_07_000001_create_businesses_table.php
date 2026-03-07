<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Ghana');
            $table->string('tin')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('logo')->nullable();
            $table->string('website')->nullable();
            $table->string('industry')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_registered')->default(false);
            $table->enum('status', ['active', 'suspended', 'pending'])->default('pending');
            $table->enum('plan', ['free', 'starter', 'pro', 'enterprise'])->default('free');
            $table->string('signature_name')->nullable();
            $table->string('signature_image')->nullable();
            $table->json('branding')->nullable();
            $table->string('invoice_prefix')->default('INV');
            $table->integer('next_invoice_number')->default(1);
            $table->string('contract_prefix')->default('CON');
            $table->integer('next_contract_number')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
