<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'nhil_rate')) {
                $table->decimal('nhil_rate', 5, 2)->default(0)->after('vat_amount');
            }
            if (!Schema::hasColumn('invoices', 'nhil_amount')) {
                $table->decimal('nhil_amount', 12, 2)->default(0)->after('nhil_rate');
            }
            if (!Schema::hasColumn('invoices', 'getfund_rate')) {
                $table->decimal('getfund_rate', 5, 2)->default(0)->after('nhil_amount');
            }
            if (!Schema::hasColumn('invoices', 'getfund_amount')) {
                $table->decimal('getfund_amount', 12, 2)->default(0)->after('getfund_rate');
            }
            if (!Schema::hasColumn('invoices', 'covid_levy_rate')) {
                $table->decimal('covid_levy_rate', 5, 2)->default(0)->after('getfund_amount');
            }
            if (!Schema::hasColumn('invoices', 'covid_levy_amount')) {
                $table->decimal('covid_levy_amount', 12, 2)->default(0)->after('covid_levy_rate');
            }
            if (!Schema::hasColumn('invoices', 'discount_rate')) {
                $table->decimal('discount_rate', 5, 2)->default(0)->after('covid_levy_amount');
            }
            if (!Schema::hasColumn('invoices', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_rate');
            }
            if (!Schema::hasColumn('invoices', 'use_ghana_tax')) {
                $table->boolean('use_ghana_tax')->default(false)->after('discount_amount');
            }
        });

        Schema::table('businesses', function (Blueprint $table) {
            if (!Schema::hasColumn('businesses', 'use_ghana_tax')) {
                $table->boolean('use_ghana_tax')->default(false)->after('vat_rate');
            }
            if (!Schema::hasColumn('businesses', 'default_nhil_rate')) {
                $table->decimal('default_nhil_rate', 5, 2)->default(2.5)->after('use_ghana_tax');
            }
            if (!Schema::hasColumn('businesses', 'default_getfund_rate')) {
                $table->decimal('default_getfund_rate', 5, 2)->default(2.5)->after('default_nhil_rate');
            }
            if (!Schema::hasColumn('businesses', 'default_covid_levy_rate')) {
                $table->decimal('default_covid_levy_rate', 5, 2)->default(1.0)->after('default_getfund_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $cols = ['nhil_rate','nhil_amount','getfund_rate','getfund_amount',
                     'covid_levy_rate','covid_levy_amount','discount_rate','discount_amount','use_ghana_tax'];
            $table->dropColumn(array_filter($cols, fn($c) => Schema::hasColumn('invoices', $c)));
        });
        Schema::table('businesses', function (Blueprint $table) {
            $cols = ['use_ghana_tax','default_nhil_rate','default_getfund_rate','default_covid_levy_rate'];
            $table->dropColumn(array_filter($cols, fn($c) => Schema::hasColumn('businesses', $c)));
        });
    }
};
