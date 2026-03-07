<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;
use App\Models\Business;

class SeedAdminPricing extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaults = Business::getDefaultPlanLimits();

        $pricing = [
            'plans' => [
                [
                    'id' => 'free',
                    'name' => 'Free',
                    'price' => 0,
                    'annual_price' => 0,
                    'tagline' => 'Look serious from day one. No credit card. No excuses.',
                    'isHighlighted' => false,
                    'features' => [
                        '5 invoices / month',
                        '2 contracts or proposals / month',
                        'Up to 5 clients',
                        '1 business profile',
                        '1 professional invoice template',
                        'PDF invoices & receipts',
                        'Email invoice sending',
                        'Manual payment recording',
                    ],
                    'limits' => $defaults['free'],
                ],
                [
                    'id' => 'growth',
                    'name' => 'Growth',
                    'price' => 75,
                    'annual_price' => 720,
                    'tagline' => 'For freelancers and small businesses ready to look established and save time.',
                    'isHighlighted' => false,
                    'features' => [
                        'Everything in Free, plus:',
                        '100 invoices / month',
                        '20 contracts & proposals / month',
                        'Up to 50 clients',
                        '2 business profiles',
                        'Expense tracking + receipt uploads',
                        'Basic Profit & Loss dashboard',
                        'Client portal dashboard',
                        '3 team members',
                    ],
                    'limits' => $defaults['growth'],
                ],
                [
                    'id' => 'pro',
                    'name' => 'Pro',
                    'price' => 149,
                    'annual_price' => 1428,
                    'tagline' => 'For businesses running at full speed. EsperWorks becomes your command center.',
                    'isHighlighted' => true,
                    'features' => [
                        'Everything in Growth, plus:',
                        'Unlimited invoices & documents',
                        'Unlimited clients & templates',
                        '5 business profiles',
                        'AI assistant (Invoices/Proposals)',
                        'Advanced analytics & revenue trends',
                        'MoMo, Bank, Card integration',
                        '5 team members',
                        'Dedicated priority support',
                    ],
                    'limits' => $defaults['pro'],
                ],
            ],
            'currency' => 'GHS',
            'currency_symbol' => 'GH₵',
        ];

        Setting::set('pricing', $pricing);
        Business::clearPricingCache();
        
        $this->command->info('Admin pricing settings seeded successfully!');
    }
}
