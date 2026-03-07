<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('invoice_template_id')->nullable()->after('client_id')->constrained('invoice_templates')->onDelete('set null');
            $table->index('invoice_template_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['invoice_template_id']);
            $table->dropIndex(['invoice_template_id']);
            $table->dropColumn('invoice_template_id');
        });
    }
};
