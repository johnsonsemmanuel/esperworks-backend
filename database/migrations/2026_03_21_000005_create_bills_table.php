<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_name');
            $table->string('supplier_email')->nullable();
            $table->string('bill_number')->nullable();   // supplier's reference number
            $table->enum('status', ['draft', 'due', 'paid', 'overdue', 'cancelled'])->default('due');
            $table->string('category')->nullable();      // rent, utilities, salaries, supplies, etc.
            $table->date('bill_date');
            $table->date('due_date');
            $table->string('currency', 10)->default('GHS');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->date('paid_date')->nullable();
            $table->text('description')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
