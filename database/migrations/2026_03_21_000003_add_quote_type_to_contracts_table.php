<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modify the enum to add 'quote' and add quote-specific fields
        Schema::table('contracts', function (Blueprint $table) {
            $table->decimal('quote_subtotal', 12, 2)->nullable()->after('value');
            $table->decimal('quote_vat_rate', 5, 2)->nullable()->after('quote_subtotal');
            $table->decimal('quote_total', 12, 2)->nullable()->after('quote_vat_rate');
            $table->date('quote_valid_until')->nullable()->after('quote_total');
            $table->string('quote_status')->nullable()->after('quote_valid_until'); // draft, sent, accepted, declined, expired
            $table->json('quote_items')->nullable()->after('quote_status'); // [{description, quantity, rate}]
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['quote_subtotal', 'quote_vat_rate', 'quote_total', 'quote_valid_until', 'quote_status', 'quote_items']);
        });
    }
};
