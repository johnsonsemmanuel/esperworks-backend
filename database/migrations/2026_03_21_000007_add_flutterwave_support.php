<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'gateway')) {
                $table->string('gateway')->default('paystack')->after('paystack_access_code');
            }
            if (!Schema::hasColumn('payments', 'gateway_transaction_id')) {
                $table->string('gateway_transaction_id')->nullable()->after('gateway');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'payment_gateway')) {
                $table->string('payment_gateway')->default('paystack')->after('paystack_subaccount_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $cols = array_filter(['gateway', 'gateway_transaction_id'], fn($c) => Schema::hasColumn('payments', $c));
            if ($cols) $table->dropColumn(array_values($cols));
        });
        Schema::table('businesses', function (Blueprint $table) {
            if (Schema::hasColumn('businesses', 'payment_gateway')) {
                $table->dropColumn('payment_gateway');
            }
        });
    }
};
