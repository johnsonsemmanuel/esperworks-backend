<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->json('client_response')->nullable()->after('client_signed_at');
            $table->timestamp('client_response_at')->nullable()->after('client_response');
            $table->string('client_response_ip', 45)->nullable()->after('client_response_at');
            $table->string('client_response_user_agent')->nullable()->after('client_response_ip');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['client_response', 'client_response_at', 'client_response_ip', 'client_response_user_agent']);
        });
    }
};
