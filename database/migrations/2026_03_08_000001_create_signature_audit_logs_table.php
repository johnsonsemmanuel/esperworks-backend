<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('signable');
            $table->string('event');
            $table->string('signer_type');
            $table->string('signer_name');
            $table->string('signer_email')->nullable();
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->string('device_type')->nullable();
            $table->string('browser')->nullable();
            $table->string('os')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone')->nullable();
            $table->string('signature_method')->nullable();
            $table->string('document_hash');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['signable_type', 'signable_id', 'event']);
        });

        // Add content_hash and signature_method columns to contracts
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('content_hash')->nullable()->after('pdf_path');
            $table->string('signature_method')->nullable()->after('content_hash');
        });

        // Add content_hash column to invoices
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('content_hash')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_audit_logs');

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['content_hash', 'signature_method']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['content_hash']);
        });
    }
};
