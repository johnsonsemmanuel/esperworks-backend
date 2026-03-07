<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('paystack_subaccount_code')->nullable()->after('plan');
            $table->string('settlement_bank')->nullable()->after('paystack_subaccount_code');
            $table->string('bank_account_number')->nullable()->after('settlement_bank');
            $table->string('bank_account_name')->nullable()->after('bank_account_number');
            $table->string('bank_code')->nullable()->after('bank_account_name');
            $table->boolean('payment_verified')->default(false)->after('bank_code');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'paystack_subaccount_code', 'settlement_bank',
                'bank_account_number', 'bank_account_name', 'bank_code',
                'payment_verified',
            ]);
        });
    }
};
