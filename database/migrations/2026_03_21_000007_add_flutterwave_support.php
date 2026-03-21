<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway')->default('paystack')->after('paystack_access_code');
            $table->string('gateway_transaction_id')->nullable()->after('gateway');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->string('payment_gateway')->default('paystack')->after('paystack_subaccount_code');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['gateway', 'gateway_transaction_id']);
        });
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('payment_gateway');
        });
    }
};
