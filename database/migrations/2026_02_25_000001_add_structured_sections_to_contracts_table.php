<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->longText('content')->nullable()->change();
            // Shared: industry template (contract + proposal)
            $table->string('industry_type', 80)->nullable()->after('type');

            // Pricing (both)
            $table->string('pricing_type', 40)->nullable()->after('value'); // fixed, hourly, milestone_based

            // Section 2–4 shared: scope of work (contract + proposal)
            $table->json('scope_of_work')->nullable()->after('content'); // { service_description, deliverables: [], exclusions }

            // Section 3: milestones (both)
            $table->json('milestones')->nullable()->after('expiry_date'); // [ { name, description, due_date, amount } ]

            // Section 4: payment terms (contract + proposal)
            $table->json('payment_terms')->nullable()->after('milestones'); // { schedule_text, late_payment_clause }

            // Contract-specific: Section 5–7
            $table->json('ownership_rights')->nullable()->after('payment_terms'); // { client_owns_deliverables, freelancer_portfolio_rights, ip_after_payment }
            $table->boolean('confidentiality_enabled')->default(false)->after('ownership_rights');
            $table->unsignedSmallInteger('termination_notice_days')->nullable()->after('confidentiality_enabled');
            $table->string('termination_payment_note', 500)->nullable()->after('termination_notice_days');

            // Proposal-specific: Sections 2–3, 6–8
            $table->longText('introduction_message')->nullable()->after('termination_payment_note');
            $table->json('problem_solution')->nullable()->after('introduction_message'); // { client_problem, your_solution }
            $table->json('packages')->nullable()->after('problem_solution'); // [ { name, description, price, deliverables: [] } ]
            $table->json('add_ons')->nullable()->after('packages'); // [ { label, price, period } ]
            $table->longText('terms_lightweight')->nullable()->after('add_ons');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn([
                'industry_type', 'pricing_type', 'scope_of_work', 'milestones', 'payment_terms',
                'ownership_rights', 'confidentiality_enabled', 'termination_notice_days', 'termination_payment_note',
                'introduction_message', 'problem_solution', 'packages', 'add_ons', 'terms_lightweight',
            ]);
        });
    }
};
