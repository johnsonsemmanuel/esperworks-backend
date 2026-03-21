<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete(); // the invoice this credits against
            $table->string('credit_note_number')->unique();
            $table->enum('status', ['draft', 'issued', 'applied', 'void'])->default('draft');
            $table->date('issue_date');
            $table->string('currency', 10)->default('GHS');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('amount_applied', 12, 2)->default(0); // how much has been used
            $table->string('reason')->nullable(); // overpayment, returned goods, error correction, etc.
            $table->text('notes')->nullable();
            $table->json('items')->nullable(); // [{description, quantity, rate, amount}]
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
