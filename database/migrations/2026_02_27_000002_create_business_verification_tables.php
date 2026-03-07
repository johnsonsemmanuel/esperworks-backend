<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Create business verification documents table
        Schema::create('business_verification_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->enum('document_type', [
                'certificate_of_incorporation',
                'tin_certificate', 
                'owner_id',
                'proof_of_address',
                'tax_clearance_certificate',
                'operating_license'
            ])->notNullable();
            $table->string('file_path', 255)->notNullable();
            $table->string('file_name', 255)->notNullable();
            $table->string('original_name', 255)->notNullable();
            $table->integer('file_size')->notNullable();
            $table->string('mime_type', 100)->notNullable();
            $table->enum('status', ['pending', 'under_review', 'approved', 'rejected', 'resubmitted'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained()->null()->onDelete('set null');
            
            $table->index(['business_id', 'status']);
            $table->index(['document_type', 'status']);
            $table->index('status');
        });

        // Add verification status to businesses table
        Schema::table('businesses', function (Blueprint $table) {
            $table->enum('verification_status', ['not_submitted', 'pending', 'under_review', 'approved', 'rejected', 'resubmitted'])->default('not_submitted');
            $table->boolean('verification_badge')->default(false);
            $table->timestamp('verification_submitted_at')->nullable();
            $table->timestamp('verification_approved_at')->nullable();
            $table->timestamp('verification_rejected_at')->nullable();
            $table->text('verification_rejection_reason')->nullable();
            $table->string('verification_reference')->nullable()->unique();
            $table->index('verification_status');
            $table->index('verification_badge');
        });

        // Create verification reviews table for audit trail
        Schema::create('verification_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained()->null()->onDelete('set null');
            $table->enum('action', ['submitted', 'approved', 'rejected', 'requested_resubmit']);
            $table->text('notes')->nullable();
            $table->json('previous_data')->nullable(); // Store previous state for audit
            $table->timestamps();
            
            $table->index(['business_id', 'action']);
            $table->index(['reviewed_by', 'action']);
            $table->index('created_at');
        });

        // Create verification badges table
        Schema::create('verification_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('badge_type', 50)->notNullable(); // 'verified', 'trusted', 'premium'
            $table->string('badge_level', 20)->default('standard'); // 'basic', 'standard', 'premium'
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('issuance_notes')->nullable();
            $table->timestamps();
            
            $table->index(['business_id', 'badge_type']);
            $table->index(['badge_type', 'is_active']);
        });

        // Create verification requirements table
        Schema::create('verification_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('business_type', 50)->notNullable(); // 'sole_proprietor', 'partnership', 'limited', 'company'
            $table->json('required_documents')->notNullable(); // Array of required document types
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('business_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_badges');
        Schema::dropIfExists('verification_reviews');
        Schema::dropIfExists('verification_requirements');
        Schema::dropIfExists('business_verification_documents');
        
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('verification_status');
            $table->dropColumn('verification_badge');
            $table->dropColumn('verification_submitted_at');
            $table->dropColumn('verification_approved_at');
            $table->dropColumn('verification_rejected_at');
            $table->dropColumn('verification_rejection_reason');
            $table->dropColumn('verification_reference');
        });
    }
};
