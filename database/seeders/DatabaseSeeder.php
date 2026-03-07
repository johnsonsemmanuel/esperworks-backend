<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Business;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Admin User (idempotent) ──
        $admin = User::firstOrCreate(
            ['email' => 'admin@esperworks.com'],
            [
                'name' => 'EsperWorks Admin',
                'password' => 'Admin@2026',
                'role' => 'admin',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        // ── Business Owner (idempotent) ──
        $owner = User::firstOrCreate(
            ['email' => 'kofi@esperworks.com'],
            [
                'name' => 'Kofi Asante',
                'password' => 'Password@2026',
                'role' => 'business_owner',
                'phone' => '+233 24 123 4567',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        // ── Business (idempotent: one per owner by name) ──
        $business = Business::firstOrCreate(
            ['user_id' => $owner->id, 'name' => 'EsperWorks Ltd'],
            [
                'email' => 'hello@esperworks.com',
                'phone' => '+233 24 123 4567',
                'address' => '15 Independence Ave, Accra',
                'city' => 'Accra',
                'country' => 'Ghana',
                'tin' => 'GHA-TIN-123456',
                'registration_number' => 'BN-2024-001234',
                'industry' => 'Technology',
                'description' => 'Modern invoicing & finance platform for African businesses',
                'is_registered' => true,
                'status' => 'active',
                'plan' => 'pro',
                'invoice_prefix' => 'INV',
                'next_invoice_number' => 12,
                'contract_prefix' => 'CON',
                'next_contract_number' => 5,
                'branding' => [
                    'sidebarColor' => '#29235c',
                    'accentColor' => '#00983a',
                    'invoiceAccent' => '#00983a',
                    'invoiceHeaderBg' => '#29235c',
                    'invoiceFont' => 'Manrope',
                ],
            ]
        );

        Subscription::firstOrCreate(
            ['business_id' => $business->id],
            [
                'plan' => 'pro',
                'amount' => 49,
                'currency' => 'GHS',
                'status' => 'active',
                'starts_at' => now()->startOfMonth(),
                'ends_at' => now()->endOfMonth(),
            ]
        );

        // Only seed clients/invoices/expenses/contracts if this business has no invoices yet
        if ($business->invoices()->count() === 0) {
            $this->seedBusinessData($business);
        }

        // ── Second Business Owner (idempotent) ──
        $owner2 = User::firstOrCreate(
            ['email' => 'ama@techghana.com'],
            [
                'name' => 'Ama Mensah',
                'password' => 'Password@2026',
                'role' => 'business_owner',
                'phone' => '+233 20 555 6789',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        Business::firstOrCreate(
            ['user_id' => $owner2->id, 'name' => 'TechGhana Solutions'],
            [
                'email' => 'info@techghana.com',
                'phone' => '+233 20 555 6789',
                'address' => '24 Oxford Street, Osu',
                'city' => 'Accra',
                'country' => 'Ghana',
                'industry' => 'Technology',
                'is_registered' => true,
                'status' => 'active',
                'plan' => 'starter',
            ]
        );

        $this->command->info('Database seeded successfully!');
        $this->command->info('Admin: admin@esperworks.com / Admin@2026');
        $this->command->info('Business Owner: kofi@esperworks.com / Password@2026');
        $this->command->info('Client Portal: billing@accradigital.com / Client@2026');
    }

    private function seedBusinessData(Business $business): void
    {
        $owner = $business->owner;

        // ── Clients ──
        $clientsData = [
            ['name' => 'Accra Digital Hub', 'email' => 'billing@accradigital.com', 'phone' => '+233 24 123 4567', 'city' => 'Accra', 'company' => 'Accra Digital Hub'],
            ['name' => 'Kumasi Craft Co.', 'email' => 'accounts@kumasicraft.gh', 'phone' => '+233 20 987 6543', 'city' => 'Kumasi', 'company' => 'Kumasi Craft Co.'],
            ['name' => 'Tema Port Services', 'email' => 'finance@temaport.com', 'phone' => '+233 26 555 1234', 'city' => 'Tema', 'company' => 'Tema Port Services'],
            ['name' => 'Cape Coast Tours', 'email' => 'pay@cctours.gh', 'phone' => '+233 24 777 8899', 'city' => 'Cape Coast', 'company' => 'Cape Coast Tours'],
            ['name' => 'Takoradi Logistics', 'email' => 'billing@taklogi.com', 'phone' => '+233 31 222 3344', 'city' => 'Takoradi', 'company' => 'Takoradi Logistics'],
        ];

        $clients = [];
        foreach ($clientsData as $cd) {
            $clients[] = Client::firstOrCreate(
                ['business_id' => $business->id, 'email' => $cd['email']],
                [
                    'name' => $cd['name'],
                    'phone' => $cd['phone'],
                    'city' => $cd['city'],
                    'country' => 'Ghana',
                    'company' => $cd['company'],
                    'status' => 'active',
                ]
            );
        }

        // Invite first client to portal (idempotent user)
        $clientUser = User::firstOrCreate(
            ['email' => 'billing@accradigital.com'],
            [
                'name' => 'Accra Digital Hub',
                'password' => 'Client@2026',
                'role' => 'client',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );
        $clients[0]->update(['user_id' => $clientUser->id, 'portal_invited' => true, 'portal_invited_at' => now()]);

        // ── Invoices ──
        $invoicesData = [
            ['client' => 0, 'number' => 'INV-001', 'status' => 'paid', 'total' => 4500, 'days_ago' => 30, 'due_days' => 0],
            ['client' => 0, 'number' => 'INV-002', 'status' => 'paid', 'total' => 8200, 'days_ago' => 45, 'due_days' => 0],
            ['client' => 1, 'number' => 'INV-003', 'status' => 'sent', 'total' => 2800, 'days_ago' => 10, 'due_days' => 20],
            ['client' => 2, 'number' => 'INV-004', 'status' => 'overdue', 'total' => 12000, 'days_ago' => 40, 'due_days' => -10],
            ['client' => 3, 'number' => 'INV-005', 'status' => 'draft', 'total' => 6000, 'days_ago' => 2, 'due_days' => 28],
            ['client' => 4, 'number' => 'INV-006', 'status' => 'paid', 'total' => 3200, 'days_ago' => 60, 'due_days' => 0],
            ['client' => 1, 'number' => 'INV-007', 'status' => 'viewed', 'total' => 5500, 'days_ago' => 5, 'due_days' => 25],
            ['client' => 0, 'number' => 'INV-008', 'status' => 'sent', 'total' => 9800, 'days_ago' => 3, 'due_days' => 27],
            ['client' => 2, 'number' => 'INV-009', 'status' => 'paid', 'total' => 7200, 'days_ago' => 90, 'due_days' => 0],
            ['client' => 4, 'number' => 'INV-010', 'status' => 'paid', 'total' => 15000, 'days_ago' => 120, 'due_days' => 0],
            ['client' => 3, 'number' => 'INV-011', 'status' => 'sent', 'total' => 4200, 'days_ago' => 7, 'due_days' => 23],
        ];

        $lineItems = [
            ['Web Development', 'UI/UX Design', 'Hosting Setup'],
            ['E-commerce Platform', 'Payment Integration', 'Training'],
            ['Logo Design', 'Brand Guidelines'],
            ['IT Consulting (Jan)', 'Server Maintenance', 'Security Audit'],
            ['Mobile App Development', 'API Integration'],
            ['Social Media Management', 'Content Creation'],
            ['SEO Optimization', 'Analytics Setup', 'Report'],
            ['Website Redesign', 'Migration', 'Testing'],
            ['Annual Maintenance', 'Support Package'],
            ['ERP Implementation', 'Custom Modules', 'Training', 'Support'],
            ['Marketing Strategy', 'Campaign Design'],
        ];

        foreach ($invoicesData as $i => $inv) {
            $issueDate = now()->subDays($inv['days_ago']);
            $dueDate = $inv['due_days'] > 0 ? now()->addDays($inv['due_days']) : $issueDate->copy()->addDays(30);
            $vatRate = 12.5;
            $subtotal = round($inv['total'] / (1 + $vatRate / 100), 2);
            $vatAmount = $inv['total'] - $subtotal;

            $invoice = Invoice::firstOrCreate(
                ['business_id' => $business->id, 'invoice_number' => $inv['number']],
                [
                    'client_id' => $clients[$inv['client']]->id,
                    'status' => $inv['status'],
                    'issue_date' => $issueDate,
                    'due_date' => $dueDate,
                    'subtotal' => $subtotal,
                    'vat_rate' => $vatRate,
                    'vat_amount' => $vatAmount,
                    'currency' => 'GHS',
                    'total' => $inv['total'],
                    'amount_paid' => $inv['status'] === 'paid' ? $inv['total'] : 0,
                    'payment_method' => 'all',
                    'signature_required' => true,
                    'client_signature_required' => true,
                    'business_signature_name' => 'Kofi Asante',
                    'business_signed_at' => $issueDate,
                    'client_signature_name' => $inv['status'] === 'paid' ? $clients[$inv['client']]->name : null,
                    'client_signed_at' => $inv['status'] === 'paid' ? $issueDate->copy()->addDays(5) : null,
                    'signing_token' => Str::random(64),
                    'sent_at' => in_array($inv['status'], ['sent', 'viewed', 'paid', 'overdue']) ? $issueDate : null,
                    'viewed_at' => in_array($inv['status'], ['viewed', 'paid']) ? $issueDate->copy()->addDays(2) : null,
                    'paid_at' => $inv['status'] === 'paid' ? $issueDate->copy()->addDays(15) : null,
                ]
            );

            if ($invoice->wasRecentlyCreated) {
                $subtotal = $invoice->subtotal;
                $itemCount = count($lineItems[$i]);
                $itemRate = round($subtotal / $itemCount, 2);
                foreach ($lineItems[$i] as $j => $desc) {
                    InvoiceItem::create([
                        'invoice_id' => $invoice->id,
                        'description' => $desc,
                        'quantity' => 1,
                        'rate' => $itemRate,
                        'amount' => $itemRate,
                        'sort_order' => $j,
                    ]);
                }
                if ($inv['status'] === 'paid') {
                    Payment::firstOrCreate(
                        ['invoice_id' => $invoice->id],
                        [
                            'business_id' => $business->id,
                            'client_id' => $clients[$inv['client']]->id,
                            'amount' => $inv['total'],
                            'currency' => 'GHS',
                            'method' => 'bank',
                            'reference' => 'PAY-' . Str::upper(Str::random(8)),
                            'status' => 'success',
                            'paid_at' => $invoice->paid_at,
                        ]
                    );
                }
            }
        }

        // ── Contracts ──
        $contractsData = [
            ['client' => 0, 'number' => 'CON-001', 'title' => 'Website Development Agreement', 'type' => 'contract', 'value' => 45000, 'status' => 'signed', 'biz_signed' => true, 'client_signed' => true],
            ['client' => 1, 'number' => 'PRO-001', 'title' => 'Brand Identity Design Proposal', 'type' => 'proposal', 'value' => 12000, 'status' => 'sent', 'biz_signed' => true, 'client_signed' => false],
            ['client' => 2, 'number' => 'CON-002', 'title' => 'Monthly Retainer Agreement', 'type' => 'contract', 'value' => 8500, 'status' => 'viewed', 'biz_signed' => true, 'client_signed' => false],
            ['client' => 4, 'number' => 'CON-003', 'title' => 'Supply Chain Consultancy', 'type' => 'contract', 'value' => 18000, 'status' => 'signed', 'biz_signed' => true, 'client_signed' => true],
        ];

        foreach ($contractsData as $cd) {
            Contract::firstOrCreate(
                ['business_id' => $business->id, 'contract_number' => $cd['number']],
                [
                    'client_id' => $clients[$cd['client']]->id,
                    'title' => $cd['title'],
                    'type' => $cd['type'],
                    'content' => "This is a sample {$cd['type']} document for {$cd['title']}.\n\nBetween EsperWorks Ltd and {$clients[$cd['client']]->name}.\n\nValue: GH₵ " . number_format($cd['value'], 2),
                    'status' => $cd['status'],
                    'value' => $cd['value'],
                    'created_date' => now()->subDays(rand(10, 60)),
                    'expiry_date' => now()->addMonths(rand(1, 6)),
                    'business_signature_name' => $cd['biz_signed'] ? 'Kofi Asante' : null,
                    'business_signed_at' => $cd['biz_signed'] ? now()->subDays(rand(5, 30)) : null,
                    'client_signature_name' => $cd['client_signed'] ? $clients[$cd['client']]->name : null,
                    'client_signed_at' => $cd['client_signed'] ? now()->subDays(rand(1, 10)) : null,
                    'signing_token' => Str::random(64),
                    'sent_at' => in_array($cd['status'], ['sent', 'viewed', 'signed']) ? now()->subDays(rand(10, 30)) : null,
                ]
            );
        }

        // ── Expenses ──
        $expensesData = [
            ['Salaries - January', 15000, 'Salaries', 'Bank Transfer', 'Staff Payroll', 30],
            ['Salaries - February', 15000, 'Salaries', 'Bank Transfer', 'Staff Payroll', 1],
            ['AWS Hosting', 850, 'Software', 'Credit Card', 'Amazon Web Services', 5],
            ['Google Workspace', 120, 'Software', 'Credit Card', 'Google', 10],
            ['Office Rent - Feb', 3500, 'Office', 'Bank Transfer', 'Landlord', 1],
            ['Internet & Phone', 450, 'Utilities', 'Mobile Money', 'Vodafone Ghana', 3],
            ['Facebook Ads', 800, 'Marketing', 'Credit Card', 'Meta Platforms', 7],
            ['Fuel - Company Car', 350, 'Transport', 'Cash', 'Shell Ghana', 4],
            ['Printer & Supplies', 280, 'Equipment', 'Mobile Money', 'Compughana', 15],
            ['Legal Consultation', 2000, 'Professional Services', 'Bank Transfer', 'Lex Associates', 20],
            ['Team Lunch', 150, 'Food', 'Cash', 'Papaye Restaurant', 2],
            ['Business Insurance', 1200, 'Insurance', 'Bank Transfer', 'Star Assurance', 35],
        ];

        foreach ($expensesData as $ed) {
            Expense::firstOrCreate(
                [
                    'business_id' => $business->id,
                    'description' => $ed[0],
                    'date' => now()->subDays($ed[5]),
                ],
                [
                    'amount' => $ed[1],
                    'category' => $ed[2],
                    'payment_method' => $ed[3],
                    'vendor' => $ed[4],
                    'status' => 'approved',
                ]
            );
        }
    }
}
