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
        Schema::table('contracts', function (Blueprint $table) {
            $table->longText('client_signature_image')->nullable()->change();
            $table->longText('business_signature_image')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->longText('client_signature_image')->nullable()->change();
            $table->longText('business_signature_image')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->text('client_signature_image')->nullable()->change();
            $table->text('business_signature_image')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->text('client_signature_image')->nullable()->change();
            $table->text('business_signature_image')->nullable()->change();
        });
    }
};
