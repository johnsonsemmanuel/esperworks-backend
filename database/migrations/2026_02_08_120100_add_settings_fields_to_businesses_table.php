<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'currency')) {
                $table->string('currency', 10)->default('GHS')->after('logo');
            }
            if (!Schema::hasColumn('businesses', 'payment_terms')) {
                $table->string('payment_terms', 30)->default('net_30')->after('currency');
            }
            if (!Schema::hasColumn('businesses', 'vat_rate')) {
                $table->string('vat_rate', 10)->default('0')->after('payment_terms');
            }
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['currency', 'payment_terms', 'vat_rate']);
        });
    }
};
