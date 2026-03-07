<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('contract_number')->unique();
            $table->string('title');
            $table->enum('type', ['contract', 'proposal'])->default('contract');
            $table->longText('content');
            $table->enum('status', ['draft', 'sent', 'viewed', 'signed', 'expired', 'declined'])->default('draft');
            $table->decimal('value', 12, 2)->default(0);
            $table->date('created_date');
            $table->date('expiry_date');
            $table->string('business_signature_name')->nullable();
            $table->string('business_signature_image')->nullable();
            $table->timestamp('business_signed_at')->nullable();
            $table->string('client_signature_name')->nullable();
            $table->string('client_signature_image')->nullable();
            $table->timestamp('client_signed_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->string('signing_token')->nullable()->unique();
            $table->timestamps();

            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
