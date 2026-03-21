<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('signature_audit_logs')) {
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
        }

        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'content_hash')) {
                $table->string('content_hash')->nullable()->after('pdf_path');
            }
            if (!Schema::hasColumn('contracts', 'signature_method')) {
                $table->string('signature_method')->nullable()->after('content_hash');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'content_hash')) {
                $table->string('content_hash')->nullable()->after('pdf_path');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_audit_logs');
        Schema::table('contracts', function (Blueprint $table) {
            $cols = array_filter(['content_hash', 'signature_method'], fn($c) => Schema::hasColumn('contracts', $c));
            if ($cols) $table->dropColumn(array_values($cols));
        });
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'content_hash')) {
                $table->dropColumn('content_hash');
            }
        });
    }
};
