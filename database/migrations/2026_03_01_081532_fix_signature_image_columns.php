<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('businesses', 'signature_image')) {
            Schema::table('businesses', function (Blueprint $table) {
                $table->longText('signature_image')->nullable()->change();
            });
        }
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'business_signature_image')) {
                $table->longText('business_signature_image')->nullable()->change();
            }
            if (Schema::hasColumn('invoices', 'client_signature_image')) {
                $table->longText('client_signature_image')->nullable()->change();
            }
        });
        Schema::table('contracts', function (Blueprint $table) {
            if (Schema::hasColumn('contracts', 'business_signature_image')) {
                $table->longText('business_signature_image')->nullable()->change();
            }
            if (Schema::hasColumn('contracts', 'client_signature_image')) {
                $table->longText('client_signature_image')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        // No-op: reverting longText to string for signature images is unnecessary
    }
};
