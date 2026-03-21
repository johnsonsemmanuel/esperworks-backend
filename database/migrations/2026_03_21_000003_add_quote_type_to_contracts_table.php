<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'quote_subtotal')) {
                $table->decimal('quote_subtotal', 12, 2)->nullable()->after('value');
            }
            if (!Schema::hasColumn('contracts', 'quote_vat_rate')) {
                $table->decimal('quote_vat_rate', 5, 2)->nullable()->after('quote_subtotal');
            }
            if (!Schema::hasColumn('contracts', 'quote_total')) {
                $table->decimal('quote_total', 12, 2)->nullable()->after('quote_vat_rate');
            }
            if (!Schema::hasColumn('contracts', 'quote_valid_until')) {
                $table->date('quote_valid_until')->nullable()->after('quote_total');
            }
            if (!Schema::hasColumn('contracts', 'quote_status')) {
                $table->string('quote_status')->nullable()->after('quote_valid_until');
            }
            if (!Schema::hasColumn('contracts', 'quote_items')) {
                $table->json('quote_items')->nullable()->after('quote_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $cols = ['quote_subtotal','quote_vat_rate','quote_total','quote_valid_until','quote_status','quote_items'];
            $table->dropColumn(array_filter($cols, fn($c) => Schema::hasColumn('contracts', $c)));
        });
    }
};
