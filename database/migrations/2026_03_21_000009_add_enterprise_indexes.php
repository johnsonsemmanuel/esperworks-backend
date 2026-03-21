<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->safeIndex('businesses', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'businesses_status_created_index');
            $table->index(['plan', 'created_at'], 'businesses_plan_created_index');
            $table->index(['user_id', 'status'], 'businesses_user_status_index');
        });

        $this->safeIndex('invoices', function (Blueprint $table) {
            $table->index(['business_id', 'status', 'created_at'], 'invoices_business_status_created_index');
            $table->index(['client_id', 'status'], 'invoices_client_status_index');
            $table->index(['due_date', 'status'], 'invoices_due_status_index');
            $table->index(['invoice_number'], 'invoices_number_index');
            $table->index(['created_at'], 'invoices_created_index');
        });

        $this->safeIndex('clients', function (Blueprint $table) {
            $table->index(['business_id', 'status'], 'clients_business_status_index');
            $table->index(['email'], 'clients_email_index');
            $table->index(['created_at'], 'clients_created_index');
        });

        $this->safeIndex('payments', function (Blueprint $table) {
            $table->index(['business_id', 'status', 'created_at'], 'payments_business_status_created_index');
            $table->index(['invoice_id', 'status'], 'payments_invoice_status_index');
            $table->index(['reference'], 'payments_reference_index');
            $table->index(['created_at'], 'payments_created_index');
        });

        $this->safeIndex('contracts', function (Blueprint $table) {
            $table->index(['business_id', 'status', 'created_at'], 'contracts_business_status_created_index');
            $table->index(['client_id', 'status'], 'contracts_client_status_index');
            $table->index(['type', 'status'], 'contracts_type_status_index');
            $table->index(['created_date'], 'contracts_created_date_index');
        });

        $this->safeIndex('expenses', function (Blueprint $table) {
            $table->index(['business_id', 'date', 'created_at'], 'expenses_business_date_created_index');
            $table->index(['category', 'date'], 'expenses_category_date_index');
            $table->index(['date'], 'expenses_date_index');
        });

        $this->safeIndex('team_members', function (Blueprint $table) {
            $table->index(['business_id', 'status'], 'team_members_business_status_index');
            $table->index(['user_id', 'status'], 'team_members_user_status_index');
            $table->index(['role', 'status'], 'team_members_role_status_index');
        });

        $this->safeIndex('activity_logs', function (Blueprint $table) {
            $table->index(['business_id', 'action', 'created_at'], 'activity_logs_business_action_created_index');
            $table->index(['user_id', 'created_at'], 'activity_logs_user_created_index');
            $table->index(['model_type', 'model_id'], 'activity_logs_model_index');
            $table->index(['created_at'], 'activity_logs_created_index');
        });

        $this->safeIndex('invoice_items', function (Blueprint $table) {
            $table->index(['invoice_id'], 'invoice_items_invoice_index');
            $table->index(['invoice_id', 'sort_order'], 'invoice_items_invoice_sort_index');
        });

        $this->safeIndex('users', function (Blueprint $table) {
            $table->index(['email'], 'users_email_index');
            $table->index(['status', 'created_at'], 'users_status_created_index');
            $table->index(['role', 'status'], 'users_role_status_index');
        });
    }

    private function safeIndex(string $table, \Closure $callback): void
    {
        try {
            Schema::table($table, $callback);
        } catch (\Throwable $e) {
            // Index already exists — skip silently
        }
    }

    public function down(): void
    {
        $drops = [
            'businesses'    => ['businesses_status_created_index', 'businesses_plan_created_index', 'businesses_user_status_index'],
            'invoices'      => ['invoices_business_status_created_index', 'invoices_client_status_index', 'invoices_due_status_index', 'invoices_number_index', 'invoices_created_index'],
            'clients'       => ['clients_business_status_index', 'clients_email_index', 'clients_created_index'],
            'payments'      => ['payments_business_status_created_index', 'payments_invoice_status_index', 'payments_reference_index', 'payments_created_index'],
            'contracts'     => ['contracts_business_status_created_index', 'contracts_client_status_index', 'contracts_type_status_index', 'contracts_created_date_index'],
            'expenses'      => ['expenses_business_date_created_index', 'expenses_category_date_index', 'expenses_date_index'],
            'team_members'  => ['team_members_business_status_index', 'team_members_user_status_index', 'team_members_role_status_index'],
            'activity_logs' => ['activity_logs_business_action_created_index', 'activity_logs_user_created_index', 'activity_logs_model_index', 'activity_logs_created_index'],
            'invoice_items' => ['invoice_items_invoice_index', 'invoice_items_invoice_sort_index'],
            'users'         => ['users_email_index', 'users_status_created_index', 'users_role_status_index'],
        ];
        foreach ($drops as $table => $indexes) {
            $this->safeIndex($table, function (Blueprint $t) use ($indexes) {
                foreach ($indexes as $idx) {
                    try { $t->dropIndex($idx); } catch (\Throwable $e) {}
                }
            });
        }
    }
};
