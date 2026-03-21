<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('credit_notes')) {
            Schema::create('credit_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
                $table->string('credit_note_number')->unique();
                $table->enum('status', ['draft', 'issued', 'applied', 'void'])->default('draft');
                $table->date('issue_date');
                $table->string('currency', 10)->default('GHS');
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('vat_amount', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->decimal('amount_applied', 12, 2)->default(0);
                $table->string('reason')->nullable();
                $table->text('notes')->nullable();
                $table->json('items')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status']);
                $table->index(['invoice_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
