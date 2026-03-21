<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Business extends Model
{
    use HasFactory;

    protected $appends = ['logo_url'];

    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'tin',
        'registration_number',
        'logo',
        'website',
        'industry',
        'description',
        'is_registered',
        'status',
        'plan',
        'trial_ends_at',
        'signature_name',
        'signature_image',
        'branding',
        'invoice_prefix',
        'next_invoice_number',
        'contract_prefix',
        'next_contract_number',
        'currency',
        'payment_terms',
        'vat_rate',
        'paystack_subaccount_code',
        'payment_gateway',
        'settlement_bank',
        'bank_account_number',
        'bank_account_name',
        'bank_code',
        'settlement_type',
        'payment_verified',
        'use_ghana_tax',
        'default_nhil_rate',
        'default_getfund_rate',
        'default_covid_levy_rate',
    ];

    protected function casts(): array
    {
        return [
            'is_registered'            => 'boolean',
            'branding'                 => 'array',
            'trial_ends_at'            => 'datetime',
            'use_ghana_tax'            => 'boolean',
            'default_nhil_rate'        => 'decimal:2',
            'default_getfund_rate'     => 'decimal:2',
            'default_covid_levy_rate'  => 'decimal:2',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($business) {
            // Set trial for new businesses
            if (!$business->trial_ends_at) {
                $trialDays = \App\Models\Setting::get('trial_days', 14);
                $business->trial_ends_at = now()->addDays($trialDays);
            }

            // Ensure plan is set
            if (!$business->plan) {
                $business->plan = 'free';
            }

            // Initialize counters
            $business->next_invoice_number = $business->next_invoice_number ?? 1;
            $business->next_contract_number = $business->next_contract_number ?? 1;
            $business->invoice_prefix = $business->invoice_prefix ?? 'INV-';
            $business->contract_prefix = $business->contract_prefix ?? 'CON-';
        });
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function trialDaysRemaining(): int
    {
        if (!$this->trial_ends_at || $this->trial_ends_at->isPast())
            return 0;
        return (int) now()->diffInDays($this->trial_ends_at, false);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function recurringInvoices(): HasMany
    {
        return $this->hasMany(RecurringInvoice::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(\App\Models\Product::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(\App\Models\CreditNote::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(\App\Models\Bill::class);
    }

    public function invoiceTemplates(): HasMany
    {
        return $this->hasMany(InvoiceTemplate::class);
    }

    public function generateInvoiceNumber(): string
    {
        // Atomic increment to prevent race conditions
        $affected = \DB::table('businesses')
            ->where('id', $this->id)
            ->update(['next_invoice_number' => \DB::raw('next_invoice_number + 1')]);

        $currentNumber = \DB::table('businesses')->where('id', $this->id)->value('next_invoice_number') - 1;
        $number = str_pad($currentNumber, 3, '0', STR_PAD_LEFT);

        $abbreviation = $this->getBusinessAbbreviation();

        return $abbreviation . '/' . $number;
    }

    public function getBusinessAbbreviation(): string
    {
        $name = $this->name;

        // Remove common words and clean up
        $words = explode(' ', $name);
        $filteredWords = array_filter($words, function ($word) {
            $lowerWord = strtolower($word);
            return !in_array($lowerWord, ['and', 'the', 'of', 'in', 'at', 'to', 'for', 'with', 'on', 'by', 'from', 'up', 'about', 'into', 'through', 'during', 'before', 'after', 'above', 'below', 'between', 'among', 'around', 'against', 'along', 'across', 'behind', 'beyond', 'plus', 'except', 'but', 'nor', 'yet', 'so', 'since', 'until', 'while', 'where', 'when', 'why', 'how', 'all', 'any', 'both', 'each', 'few', 'more', 'most', 'other', 'some', 'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too', 'very', 'can', 'will', 'just', 'should', 'now']);
        });

        // Take first 3 words or first 8 characters, whichever is shorter
        $abbreviation = '';
        foreach ($filteredWords as $word) {
            $abbreviation .= strtoupper(substr($word, 0, 1));
            if (strlen($abbreviation) >= 3)
                break;
        }

        // If no words or abbreviation too short, use first 8 characters of name
        if (empty($abbreviation) || strlen($abbreviation) < 2) {
            $abbreviation = strtoupper(substr($name, 0, 8));
        }

        // Limit to 8 characters max
        return substr($abbreviation, 0, 8);
    }

    public function generateContractNumber(string $type = 'contract'): string
    {
        // Atomic increment to prevent race conditions
        \DB::table('businesses')
            ->where('id', $this->id)
            ->update(['next_contract_number' => \DB::raw('next_contract_number + 1')]);

        $currentNumber = \DB::table('businesses')->where('id', $this->id)->value('next_contract_number') - 1;
        $number = str_pad($currentNumber, 3, '0', STR_PAD_LEFT);

        $abbreviation = $this->getBusinessAbbreviation();
        $typePrefix = $type === 'proposal' ? 'P' : 'C';

        return $abbreviation . '-' . $typePrefix . $number;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canCreateInvoice(): bool
    {
        $limits = $this->getPlanLimits();
        $cap = $limits['invoices'] ?? 5;
        if ($cap === -1) return true;
        $monthlyCount = $this->invoices()->whereMonth('created_at', now()->month)->count();
        return $monthlyCount < $cap;
    }

    public function canCreateContract(): bool
    {
        $limits = $this->getPlanLimits();
        $cap = $limits['contracts'] ?? 0;
        if ($cap === -1) return true;
        $monthlyCount = $this->contracts()->whereMonth('created_at', now()->month)->count();
        return $monthlyCount < $cap;
    }

    public function canAddClient(): bool
    {
        $limits = $this->getPlanLimits();
        $cap = $limits['clients'] ?? 5;
        if ($cap === -1) return true;
        return $this->clients()->count() < $cap;
    }

    public function canAddExpense(): bool
    {
        $limits = $this->getPlanLimits();
        $cap = $limits['expenses'] ?? 0;
        if ($cap === -1) return true;
        $monthlyCount = $this->expenses()->whereMonth('created_at', now()->month)->count();
        return $monthlyCount < $cap;
    }

    public function canCreateRecurringInvoice(): bool
    {
        $limits = $this->getPlanLimits();
        $cap = $limits['recurring_invoices'] ?? 0;
        if ($cap === -1) return true;
        $recurringCount = $this->recurringInvoices()->where('is_active', true)->count();
        return $recurringCount < $cap;
    }

    public function canCreateInvoiceTemplate(): bool
    {
        $limits = $this->getPlanLimits();
        $cap = $limits['invoice_templates'] ?? 1;
        if ($cap === -1) return true;
        return $this->invoiceTemplates()->count() < $cap;
    }

    public function canAddTeamMember(): bool
    {
        $limits = $this->getPlanLimits();
        $cap = $limits['team_members'] ?? $limits['users'] ?? 1;
        if ($cap === -1) return true;
        $teamCount = 1; // owner always counts
        try {
            $teamCount += \DB::table('team_members')
                ->where('business_id', $this->id)
                ->where('status', 'active')
                ->count();
        } catch (\Exception $e) {
            // If team_members table doesn't exist
        }
        return $teamCount < $cap;
    }

    public function canUseStorage(): bool
    {
        return $this->canAddStorage(0);
    }

    /** Check if adding additionalGb would stay within plan storage limit. */
    public function canAddStorage(float $additionalGb = 0): bool
    {
        $limits = $this->getPlanLimits();
        $cap = $limits['storage_gb'] ?? 1;
        if ($cap === -1) return true;
        $used = $this->getStorageUsed();
        return ($used + $additionalGb) < $cap;
    }

    /**
     * Full default limits per plan (single source for code fallbacks).
     * Admin pricing (Setting) should include these keys; file/Setting can override.
     */
    public static function getDefaultPlanLimits(): array
    {
        return [
            'free' => [
                'invoices'             => 5,
                'quotes'               => 3,
                'contracts'            => 2,
                'proposals'            => 2,
                'clients'              => 5,
                'expenses'             => 0,
                'bills'                => 0,
                'credit_notes'         => 0,
                'products'             => 10,
                'team_members'         => 1,
                'users'                => 1,
                'businesses'           => 1,
                'storage_gb'           => 1,
                'recurring_invoices'   => 0,
                'invoice_templates'    => 1,
                'branding'             => 0,
                'client_portal'        => 0,
                'accounting_dashboard' => 0,
                'ghana_tax'            => -1,  // always available
                'whatsapp_share'       => -1,  // always available
            ],
            'growth' => [
                'invoices'             => 100,
                'quotes'               => 50,
                'contracts'            => 20,
                'proposals'            => 20,
                'clients'              => 50,
                'expenses'             => -1,
                'bills'                => -1,
                'credit_notes'         => 10,
                'products'             => -1,
                'team_members'         => 3,
                'users'                => 3,
                'businesses'           => 2,
                'storage_gb'           => 10,
                'recurring_invoices'   => 5,
                'invoice_templates'    => 5,
                'branding'             => -1,
                'client_portal'        => -1,
                'accounting_dashboard' => -1,
                'ghana_tax'            => -1,
                'whatsapp_share'       => -1,
            ],
            'pro' => [
                'invoices'             => -1,
                'quotes'               => -1,
                'contracts'            => -1,
                'proposals'            => -1,
                'clients'              => -1,
                'expenses'             => -1,
                'bills'                => -1,
                'credit_notes'         => -1,
                'products'             => -1,
                'team_members'         => -1,
                'users'                => -1,
                'businesses'           => -1,
                'storage_gb'           => -1,
                'recurring_invoices'   => -1,
                'invoice_templates'    => -1,
                'branding'             => -1,
                'client_portal'        => -1,
                'accounting_dashboard' => -1,
                'ghana_tax'            => -1,
                'whatsapp_share'       => -1,
            ],
        ];
    }

    /**
     * Get pricing config: Setting first, then pricing.json, then defaults.
     * Cache cleared when admin updates pricing.
     */
    public static function getPricingConfig(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('pricing_plans_config', 300, function () {
            $pricing = \App\Models\Setting::get('pricing');
            if ($pricing && !empty($pricing['plans'] ?? [])) {
                return $pricing;
            }
            $pricingPath = storage_path('app/pricing.json');
            if (file_exists($pricingPath)) {
                $fromFile = json_decode(file_get_contents($pricingPath), true);
                if (!empty($fromFile['plans'] ?? [])) {
                    return $fromFile;
                }
            }
            $defaults = self::getDefaultPlanLimits();
            return [
                'plans' => [
                    [
                        'id' => 'free',
                        'name' => 'Free',
                        'price' => 0,
                        'annual_price' => 0,
                        'isHighlighted' => false,
                        'tagline' => 'Look serious from day one. No credit card. No excuses.',
                        'features' => [
                            '5 invoices / month',
                            '3 quotes / month',
                            '2 contracts or proposals / month',
                            'Up to 5 clients',
                            'Product catalogue (10 items)',
                            'Ghana GRA tax breakdown (VAT, NHIL, GETFund, COVID levy)',
                            'WhatsApp invoice sharing',
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
                        'isHighlighted' => true,
                        'tagline' => 'For freelancers and small businesses ready to look established and save time.',
                        'features' => [
                            'Everything in Free, plus:',
                            '100 invoices + 50 quotes / month',
                            '20 contracts & proposals / month',
                            'Unlimited product catalogue',
                            'Bills & accounts payable tracking',
                            '10 credit notes / month',
                            'Up to 50 clients',
                            '2 business profiles',
                            '5 recurring invoice schedules',
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
                        'isHighlighted' => false,
                        'tagline' => 'For businesses running at full speed. EsperWorks becomes your command center.',
                        'features' => [
                            'Everything in Growth, plus:',
                            'Unlimited invoices, quotes & documents',
                            'Unlimited clients, products & templates',
                            'Unlimited bills & credit notes',
                            'Unlimited recurring invoice schedules',
                            '5 business profiles',
                            'AI assistant (Invoices/Proposals)',
                            'Advanced analytics & revenue trends',
                            'MoMo, Bank, Card integration',
                            'Unlimited team members',
                            'Dedicated priority support',
                        ],
                        'limits' => $defaults['pro'],
                    ],
                ],
                'currency' => 'GHS',
                'currency_symbol' => 'GH₵',
            ];
        });
    }

    public static function planDisplayName(string $plan): string
    {
        return match ($plan) {
            'free' => 'Free',
            'growth' => 'Growth',
            'pro' => 'Pro',
            default => ucfirst($plan),
        };
    }

    /**
     * Get limits for a given plan (used by middleware and controllers).
     * Returns normalized limits including both 'users' and 'team_members' for compatibility.
     */
    public static function getPlanLimitsForPlan(string $plan): array
    {
        $pricing = self::getPricingConfig();
        $defaults = self::getDefaultPlanLimits();
        foreach ($pricing['plans'] ?? [] as $p) {
            if (isset($p['id']) && $p['id'] === $plan && isset($p['limits'])) {
                $limits = $p['limits'];
                if (!isset($limits['team_members']) && isset($limits['users'])) {
                    $limits['team_members'] = $limits['users'];
                }
                if (!isset($limits['users']) && isset($limits['team_members'])) {
                    $limits['users'] = $limits['team_members'];
                }
                return $limits;
            }
        }
        $limits = $defaults[$plan] ?? $defaults['free'];
        $limits['team_members'] = $limits['team_members'] ?? $limits['users'] ?? 1;
        $limits['users'] = $limits['users'] ?? $limits['team_members'] ?? 1;
        return $limits;
    }

    public static function clearPricingCache(): void
    {
        \Illuminate\Support\Facades\Cache::forget('pricing_plans_config');
    }

    public function getPlanLimits(): array
    {
        // During an active trial, grant Pro-level limits regardless of plan
        if ($this->isOnTrial()) {
            return self::getPlanLimitsForPlan('pro');
        }
        return self::getPlanLimitsForPlan($this->plan ?? 'free');
    }

    public function getUsageStats(): array
    {
        $limits = $this->getPlanLimits();

        return [
            'invoices' => [
                'limit' => $limits['invoices'],
                'used' => $this->invoices()->whereMonth('created_at', now()->month)->count(),
                'percentage' => $limits['invoices'] === -1 ? 0 : ($this->invoices()->whereMonth('created_at', now()->month)->count() / $limits['invoices']) * 100
            ],
            'contracts' => [
                'limit' => $limits['contracts'],
                'used' => $this->contracts()->where('type', 'contract')->whereMonth('created_at', now()->month)->count(),
                'percentage' => $limits['contracts'] === -1 ? 0 : ($this->contracts()->where('type', 'contract')->whereMonth('created_at', now()->month)->count() / $limits['contracts']) * 100
            ],
            'proposals' => [
                'limit' => $limits['proposals'] ?? -1,
                'used' => $this->contracts()->where('type', 'proposal')->whereMonth('created_at', now()->month)->count(),
                'percentage' => ($limits['proposals'] ?? -1) === -1 ? 0 : ($this->contracts()->where('type', 'proposal')->whereMonth('created_at', now()->month)->count() / ($limits['proposals'] ?? -1)) * 100
            ],
            'clients' => [
                'limit' => $limits['clients'],
                'used' => $this->clients()->count(),
                'percentage' => $limits['clients'] === -1 ? 0 : ($this->clients()->count() / $limits['clients']) * 100
            ],
            'expenses' => [
                'limit' => $limits['expenses'],
                'used' => $this->expenses()->whereMonth('created_at', now()->month)->count(),
                'percentage' => $limits['expenses'] === -1 ? 0 : ($this->expenses()->whereMonth('created_at', now()->month)->count() / $limits['expenses']) * 100
            ],
            'team_members' => [
                'limit' => $limits['team_members'],
                'used' => $this->getTeamMemberCount(),
                'percentage' => $limits['team_members'] === -1 ? 0 : ($this->getTeamMemberCount() / $limits['team_members']) * 100
            ],
            'storage' => [
                'limit' => $limits['storage_gb'],
                'used' => $this->getStorageUsed(),
                'percentage' => $limits['storage_gb'] === -1 ? 0 : ($this->getStorageUsed() / $limits['storage_gb']) * 100
            ]
        ];
    }

    private function getTeamMemberCount(): int
    {
        $count = 1; // owner always counts
        try {
            $count += \DB::table('team_members')
                ->where('business_id', $this->id)
                ->where('status', 'active')
                ->count();
        } catch (\Exception $e) {
            // If team_members table doesn't exist
        }
        return $count;
    }

    public function getStorageUsed(): float
    {
        $storageUsedGb = 0;
        try {
            $invoiceAttachments = $this->invoices()->whereNotNull('attachment_path')->count();
            $contractAttachments = $this->contracts()->whereNotNull('attachment_path')->count();
            $receiptAttachments = $this->expenses()->whereNotNull('receipt_path')->count();
            $pdfCount = $this->invoices()->whereNotNull('pdf_path')->count()
                + $this->contracts()->whereNotNull('pdf_path')->count();
            // Realistic estimates: logo ~2MB, attachments ~1MB each, PDFs ~0.5MB each
            $logoSizeMb = $this->logo ? 2 : 0;
            $totalMb = ($invoiceAttachments + $contractAttachments + $receiptAttachments) * 1
                + $pdfCount * 0.5
                + $logoSizeMb;
            $storageUsedGb = round($totalMb / 1024, 3);
        } catch (\Exception $e) {
            // If storage calculation fails
        }
        return $storageUsedGb;
    }

    public function getLogoUrlAttribute(): ?string
    {
        try {
            return $this->logo ? Storage::disk('public')->url($this->logo) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
