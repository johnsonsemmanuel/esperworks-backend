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
        // Business indexes
        Schema::table('businesses', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'businesses_status_created_index');
            $table->index(['plan', 'created_at'], 'businesses_plan_created_index');
            $table->index(['user_id', 'status'], 'businesses_user_status_index');
        });

        // Invoice indexes
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(['business_id', 'status', 'created_at'], 'invoices_business_status_created_index');
            $table->index(['client_id', 'status'], 'invoices_client_status_index');
            $table->index(['due_date', 'status'], 'invoices_due_status_index');
            $table->index(['invoice_number'], 'invoices_number_index');
            $table->index(['created_at'], 'invoices_created_index');
        });

        // Client indexes
        Schema::table('clients', function (Blueprint $table) {
            $table->index(['business_id', 'status'], 'clients_business_status_index');
            $table->index(['email'], 'clients_email_index');
            $table->index(['created_at'], 'clients_created_index');
        });

        // Payment indexes
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['business_id', 'status', 'created_at'], 'payments_business_status_created_index');
            $table->index(['invoice_id', 'status'], 'payments_invoice_status_index');
            $table->index(['reference'], 'payments_reference_index');
            $table->index(['created_at'], 'payments_created_index');
        });

        // Contract indexes
        Schema::table('contracts', function (Blueprint $table) {
            $table->index(['business_id', 'status', 'created_at'], 'contracts_business_status_created_index');
            $table->index(['client_id', 'status'], 'contracts_client_status_index');
            $table->index(['type', 'status'], 'contracts_type_status_index');
            $table->index(['created_date'], 'contracts_created_date_index');
        });

        // Expense indexes
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(['business_id', 'date', 'created_at'], 'expenses_business_date_created_index');
            $table->index(['category', 'date'], 'expenses_category_date_index');
            $table->index(['date'], 'expenses_date_index');
        });

        // Team member indexes
        Schema::table('team_members', function (Blueprint $table) {
            $table->index(['business_id', 'status'], 'team_members_business_status_index');
            $table->index(['user_id', 'status'], 'team_members_user_status_index');
            $table->index(['role', 'status'], 'team_members_role_status_index');
        });

        // Activity log indexes
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['business_id', 'action', 'created_at'], 'activity_logs_business_action_created_index');
            $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
            $table->index(['model_type', 'model_id'], 'activity_logs_model_index');
            $table->index(['created_at'], 'activity_logs_created_index');
        });

        // Invoice items indexes
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->index(['invoice_id'], 'invoice_items_invoice_index');
            $table->index(['invoice_id', 'sort_order'], 'invoice_items_invoice_sort_index');
        });

        // User indexes
        Schema::table('users', function (Blueprint $table) {
            $table->index(['email'], 'users_email_index');
            $table->index(['status', 'created_at'], 'users_status_created_index');
            $table->index(['role', 'status'], 'users_role_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop indexes
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex('businesses_status_created_index');
            $table->dropIndex('businesses_plan_created_index');
            $table->dropIndex('businesses_user_status_index');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_business_status_created_index');
            $table->dropIndex('invoices_client_status_index');
            $table->dropIndex('invoices_due_status_index');
            $table->dropIndex('invoices_number_index');
            $table->dropIndex('invoices_created_index');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex('clients_business_status_index');
            $table->dropIndex('clients_email_index');
            $table->dropIndex('clients_created_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_business_status_created_index');
            $table->dropIndex('payments_invoice_status_index');
            $table->dropIndex('payments_reference_index');
            $table->dropIndex('payments_created_index');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_business_status_created_index');
            $table->dropIndex('contracts_client_status_index');
            $table->dropIndex('contracts_type_status_index');
            $table->dropIndex('contracts_created_date_index');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_business_date_created_index');
            $table->dropIndex('expenses_category_date_index');
            $table->dropIndex('expenses_date_index');
        });

        Schema::table('team_members', function (Blueprint $table) {
            $table->dropIndex('team_members_business_status_index');
            $table->dropIndex('team_members_user_status_index');
            $table->dropIndex('team_members_role_status_index');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_business_action_created_index');
            $table->dropIndex('activity_logs_user_created_index');
            $table->dropIndex('activity_logs_model_index');
            $table->dropIndex('activity_logs_created_index');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex('invoice_items_invoice_index');
            $table->dropIndex('invoice_items_invoice_sort_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_email_index');
            $table->dropIndex('users_status_created_index');
            $table->dropIndex('users_role_status_index');
        });
    }
};
