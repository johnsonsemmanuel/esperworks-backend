<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('browser', 80)->nullable();
            $table->string('browser_version', 30)->nullable();
            $table->string('platform', 80)->nullable();
            $table->string('platform_version', 30)->nullable();
            $table->string('device_name', 120)->nullable();
            $table->string('country', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_active_at']);
            $table->index('device_type');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_devices');
    }
};
