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
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('GHS');
            $table->decimal('subtotal', 10, 2);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->enum('frequency', ['daily', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly']);
            $table->integer('interval_count')->default(1);
            $table->integer('day_of_month')->nullable(); // For monthly frequency
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_invoice_date');
            $table->boolean('is_active')->default(true);
            $table->integer('max_invoices')->nullable(); // Limit number of invoices
            $table->integer('invoices_created')->default(0);
            $table->foreignId('last_invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->json('items_data'); // Store invoice items as JSON
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
            $table->index(['next_invoice_date']);
            $table->index(['frequency']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
