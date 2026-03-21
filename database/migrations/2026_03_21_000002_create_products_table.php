<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('unit')->nullable();      // e.g. "hour", "piece", "kg", "day"
            $table->string('currency', 10)->default('GHS');
            $table->string('category')->nullable();  // e.g. "Service", "Product", "Labour"
            $table->boolean('is_active')->default(true);
            $table->integer('usage_count')->default(0); // how many times added to an invoice
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
            $table->index(['business_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
