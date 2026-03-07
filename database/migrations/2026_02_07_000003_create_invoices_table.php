<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number')->unique();
            $table->enum('status', ['draft', 'sent', 'viewed', 'paid', 'overdue', 'cancelled', 'partially_paid'])->default('draft');
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('currency', 10)->default('GHS');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('payment_method')->default('all');
            $table->boolean('signature_required')->default(true);
            $table->string('business_signature_name')->nullable();
            $table->string('business_signature_image')->nullable();
            $table->timestamp('business_signed_at')->nullable();
            $table->boolean('client_signature_required')->default(true);
            $table->string('client_signature_name')->nullable();
            $table->string('client_signature_image')->nullable();
            $table->timestamp('client_signed_at')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('signing_token')->nullable()->unique();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
