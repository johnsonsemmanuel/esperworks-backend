<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // Ghana tax components (added after vat_amount)
            $table->decimal('nhil_rate', 5, 2)->default(0)->after('vat_amount');    // National Health Insurance Levy
            $table->decimal('nhil_amount', 12, 2)->default(0)->after('nhil_rate');
            $table->decimal('getfund_rate', 5, 2)->default(0)->after('nhil_amount'); // Ghana Education Trust Fund levy
            $table->decimal('getfund_amount', 12, 2)->default(0)->after('getfund_rate');
            $table->decimal('covid_levy_rate', 5, 2)->default(0)->after('getfund_amount'); // COVID-19 Health Recovery Levy
            $table->decimal('covid_levy_amount', 12, 2)->default(0)->after('covid_levy_rate');
            // Discount
            $table->decimal('discount_rate', 5, 2)->default(0)->after('covid_levy_amount');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_rate');
            // Tax mode flag
            $table->boolean('use_ghana_tax')->default(false)->after('discount_amount');
        });

        Schema::table('businesses', function (Blueprint $table) {
            // Business-level Ghana tax defaults
            $table->boolean('use_ghana_tax')->default(false)->after('vat_rate');
            $table->decimal('default_nhil_rate', 5, 2)->default(2.5)->after('use_ghana_tax');
            $table->decimal('default_getfund_rate', 5, 2)->default(2.5)->after('default_nhil_rate');
            $table->decimal('default_covid_levy_rate', 5, 2)->default(1.0)->after('default_getfund_rate');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'nhil_rate', 'nhil_amount',
                'getfund_rate', 'getfund_amount',
                'covid_levy_rate', 'covid_levy_amount',
                'discount_rate', 'discount_amount',
                'use_ghana_tax',
            ]);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['use_ghana_tax', 'default_nhil_rate', 'default_getfund_rate', 'default_covid_levy_rate']);
        });
    }
};
