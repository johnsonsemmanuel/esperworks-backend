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
        Schema::table('businesses', function (Blueprint $table) {
            $table->longText('signature_image')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->longText('business_signature_image')->nullable()->change();
            $table->longText('client_signature_image')->nullable()->change();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->longText('business_signature_image')->nullable()->change();
            $table->longText('client_signature_image')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('signature_image')->nullable()->change();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('business_signature_image')->nullable()->change();
            $table->string('client_signature_image')->nullable()->change();
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->string('business_signature_image')->nullable()->change();
            $table->string('client_signature_image')->nullable()->change();
        });
    }
};
