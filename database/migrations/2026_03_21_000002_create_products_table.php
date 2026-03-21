<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2)->default(0);
                $table->string('unit')->nullable();
                $table->string('currency', 10)->default('GHS');
                $table->string('category')->nullable();
                $table->boolean('is_active')->default(true);
                $table->integer('usage_count')->default(0);
                $table->timestamps();

                $table->index(['business_id', 'is_active']);
                $table->index(['business_id', 'category']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
