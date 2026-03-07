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
        Schema::create('invoice_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('layout_config')->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('color_scheme')->nullable();
            $table->json('font_settings')->nullable();
            $table->json('header_settings')->nullable();
            $table->json('footer_settings')->nullable();
            $table->json('item_settings')->nullable();
            $table->json('total_settings')->nullable();
            $table->json('notes_settings')->nullable();
            $table->json('payment_settings')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'is_default']);
            $table->unique(['business_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_templates');
    }
};
