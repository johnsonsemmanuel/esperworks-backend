<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Business;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $query = $business->expenses();

        if ($request->category && $request->category !== 'all') {
            $query->where('category', $request->category);
        }
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('description', 'like', "%{$request->search}%")
                  ->orWhere('vendor', 'like', "%{$request->search}%");
            });
        }
        if ($request->from) $query->where('date', '>=', $request->from);
        if ($request->to) $query->where('date', '<=', $request->to);

        $expenses = $query->latest('date')->paginate($request->per_page ?? 20);

        return response()->json($expenses);
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'date' => 'required|date',
            'category' => 'required|string|max:100',
            'payment_method' => 'nullable|string|max:100',
            'vendor' => 'nullable|string|max:255',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'notes' => 'nullable|string',
        ]);

        // Check plan restrictions
        if (!$business->canAddExpense()) {
            $limits = $business->getPlanLimits();
            $plan = $business->plan ?? 'free';
            return response()->json([
                'message' => "You're operating at full capacity. Upgrade to keep workflows uninterrupted.",
                'limit' => $limits['expenses'],
                'usage' => $business->expenses()->whereMonth('created_at', now()->month)->count(),
                'plan' => $plan,
                'plan_name' => \App\Models\Business::planDisplayName($plan),
                'upgrade_required' => true,
            ], 403);
        }

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            if (!$business->canAddStorage(0.001)) {
                return response()->json([
                    'message' => "You're operating at full capacity. Upgrade to keep workflows uninterrupted.",
                    'upgrade_required' => true,
                ], 403);
            }
            $receiptPath = $request->file('receipt')->store("receipts/{$business->id}", 'public');
        }

        $expense = Expense::create([
            'business_id' => $business->id,
            'description' => $request->description,
            'amount' => $request->amount,
            'date' => $request->date,
            'category' => $request->category,
            'payment_method' => $request->payment_method,
            'vendor' => $request->vendor,
            'receipt_path' => $receiptPath,
            'notes' => $request->notes,
        ]);

        ActivityLog::log('expense.created', "Expense recorded: {$expense->description}", $expense);

        return response()->json(['message' => 'Expense recorded', 'expense' => $expense], 201);
    }

    public function show(Business $business, Expense $expense)
    {
        if ($expense->business_id !== $business->id) {
            return response()->json(['message' => 'Expense not found'], 404);
        }
        return response()->json(['expense' => $expense]);
    }

    public function update(Request $request, Business $business, Expense $expense)
    {
        if ($expense->business_id !== $business->id) {
            return response()->json(['message' => 'Expense not found'], 404);
        }
        
        // Store original values for audit trail
        $originalValues = $expense->getOriginalValues();
        $changes = [];
        
        $request->validate([
            'description' => 'sometimes|string|max:255',
            'amount' => 'sometimes|numeric|min:0.01',
            'date' => 'sometimes|date',
            'category' => 'sometimes|string|max:100',
            'payment_method' => 'nullable|string|max:100',
            'vendor' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        // Track what's being changed for audit trail
        foreach ($request->only(['description', 'amount', 'date', 'category', 'payment_method', 'vendor', 'notes']) as $field => $newValue) {
            if ($expense->$field !== $newValue) {
                $changes[$field] = [
                    'old' => $originalValues[$field] ?? null,
                    'new' => $newValue
                ];
            }
        }

        // Validate financial changes
        if (isset($changes['amount'])) {
            $validation = \App\Services\CurrencyPrecisionService::validatePrecision(
                (float) $changes['amount']['new'], 
                $business->currency ?? 'GHS'
            );
            
            if (!$validation['is_valid']) {
                return response()->json([
                    'message' => 'Amount precision error: ' . $validation['errors'][0],
                    'difference' => $validation['difference'],
                    'max_allowed' => $validation['max_allowed_difference']
                ], 422);
            }
        }

        if (isset($changes['date'])) {
            $newDate = \Carbon\Carbon::parse($changes['date']['new']);
            $oldDate = $originalValues['date'] ? \Carbon\Carbon::parse($originalValues['date']) : null;
            
            // Prevent backdating expenses beyond reasonable limits
            if ($newDate->lt(now()->subYears(7))) {
                return response()->json([
                    'message' => 'Cannot backdate expenses more than 7 years',
                    'date' => $changes['date']['new']
                ], 422);
            }
            
            // Prevent future-dating expenses
            if ($newDate->gt(now()->addDays(30))) {
                return response()->json([
                    'message' => 'Cannot create expenses more than 30 days in the future',
                    'date' => $changes['date']['new']
                ], 422);
            }
        }

        $expense->update($request->only([
            'description', 'amount', 'date', 'category', 'payment_method', 'vendor', 'notes',
        ]));

        // Log expense modification for audit trail
        ActivityLog::log('expense.updated', 
            "Expense updated: {$expense->description}" . (count($changes) > 0 ? ' - ' . json_encode($changes) : ''), 
            $expense,
            [
                'changes' => $changes,
                'modified_by' => auth()->id(),
                'modified_at' => now()->toDateTimeString(),
                'business_id' => $business->id,
            ]
        );

        return response()->json(['message' => 'Expense updated', 'expense' => $expense]);
    }

    public function destroy(Business $business, Expense $expense)
    {
        if ($expense->business_id !== $business->id) {
            return response()->json(['message' => 'Expense not found'], 404);
        }
        if ($expense->receipt_path) {
            Storage::disk('public')->delete($expense->receipt_path);
        }
        $expense->delete();

        return response()->json(['message' => 'Expense deleted']);
    }

    public function summary(Request $request, Business $business)
    {
        $from = $request->from ?? now()->startOfMonth()->toDateString();
        $to = $request->to ?? now()->endOfMonth()->toDateString();

        $expensesQuery = $business->expenses()->whereBetween('date', [$from, $to]);
        $totalExpenses = $expensesQuery->sum('amount');
        $totalCount = $expensesQuery->count();
        $avgPerExpense = $totalCount > 0 ? round($totalExpenses / $totalCount, 2) : 0;

        $byCategory = $business->expenses()
            ->whereBetween('date', [$from, $to])
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $topCategory = $byCategory->isNotEmpty() ? $byCategory->first()->category : null;

        // Monthly trend: use portable expressions so it works on SQLite (dev) and MySQL (prod).
        $driver = $business->getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            // SQLite uses strftime for date parts
            $monthlyTrend = $business->expenses()
                ->selectRaw("CAST(strftime('%Y', date) AS INTEGER) as year, CAST(strftime('%m', date) AS INTEGER) as month, SUM(amount) as total")
                ->groupByRaw("strftime('%Y', date), strftime('%m', date)")
                ->orderByRaw('year DESC, month DESC')
                ->take(12)
                ->get();
        } else {
            // MySQL / Postgres: native YEAR() / MONTH() are available
            $monthlyTrend = $business->expenses()
                ->selectRaw('YEAR(date) as year, MONTH(date) as month, SUM(amount) as total')
                ->groupByRaw('YEAR(date), MONTH(date)')
                ->orderByRaw('YEAR(date) DESC, MONTH(date) DESC')
                ->take(12)
                ->get();
        }

        return response()->json([
            'total' => $totalExpenses,
            'this_month' => $totalExpenses,
            'avg_per_expense' => $avgPerExpense,
            'top_category' => $topCategory,
            'total_count' => $totalCount,
            'period' => ['from' => $from, 'to' => $to],
            'by_category' => $byCategory,
            'monthly_trend' => $monthlyTrend,
        ]);
    }

    public function accounting(Request $request, Business $business)
    {
        $this->authorize('view', $business);

        $period = $request->period;
        if ($period === 'this_year' || empty($period)) {
            $from = now()->startOfYear()->toDateString();
            $to = now()->endOfYear()->toDateString();
        } elseif ($period === 'last_month') {
            $from = now()->subMonth()->startOfMonth()->toDateString();
            $to = now()->subMonth()->endOfMonth()->toDateString();
        } elseif ($period === 'this_quarter') {
            $from = now()->startOfQuarter()->toDateString();
            $to = now()->endOfQuarter()->toDateString();
        } elseif ($period === 'this_year') {
            $from = now()->startOfYear()->toDateString();
            $to = now()->endOfYear()->toDateString();
        } else {
            $from = $request->from ?? now()->startOfMonth()->toDateString();
            $to = $request->to ?? now()->endOfMonth()->toDateString();
        }

        // Enhanced date alignment for accounting periods
        $fromDate = \Carbon\Carbon::parse($from)->startOfDay();
        $toDate = \Carbon\Carbon::parse($to)->endOfDay();

        $income = $business->invoices()->where('status', 'paid')
            ->whereBetween('paid_at', [$fromDate, $toDate])->sum('total');
        $expenses = $business->expenses()->whereBetween('date', [$fromDate, $toDate])->sum('amount');
        $outstanding = $business->invoices()
            ->whereIn('status', ['sent', 'viewed', 'overdue'])
            ->selectRaw('SUM(total - amount_paid) as owed')->value('owed') ?? 0;

        // Validate date range for accounting accuracy
        if ($fromDate->gt($toDate)) {
            return response()->json([
                'message' => 'Start date cannot be after end date',
                'from' => $from,
                'to' => $to
            ], 422);
        }

        // Log accounting period for audit trail
        ActivityLog::log('accounting.report_generated', 
            "Accounting report generated for period {$fromDate->toDateString()} to {$toDate->toDateString()}", 
            $business,
            [
                'period' => $period,
                'from_date' => $fromDate->toDateString(),
                'to_date' => $toDate->toDateString(),
                'income' => $income,
                'expenses' => $expenses,
                'profit' => $income - $expenses
            ]
        );

        $incomeByCategory = $business->invoices()->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to])
            ->with('client:id,name')
            ->get()
            ->groupBy('client.name')
            ->map(fn($items) => $items->sum('total'))
            ->sortDesc();

        $expensesByCategory = $business->expenses()
            ->whereBetween('date', [$from, $to])
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')->orderByDesc('total')->get();

        // Monthly P&L (last 6 months) with proper date alignment - using created_at for consistency
        $pnl = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd = $month->copy()->endOfMonth();
            
            $mIncome = $business->invoices()->where('status', 'paid')
                ->whereBetween('paid_at', [$monthStart, $monthEnd])->sum('total');
            $mExpenses = $business->expenses()
                ->whereBetween('date', [$monthStart, $monthEnd])->sum('amount');
            $pnl[] = [
                'month' => $month->format('M Y'),
                'income' => $mIncome,
                'expenses' => $mExpenses,
                'profit' => $mIncome - $mExpenses,
                'period_start' => $monthStart->toDateString(),
                'period_end' => $monthEnd->toDateString(),
            ];
        }

        $recentTransactions = collect()
            ->merge($business->invoices()->where('status', 'paid')->whereBetween('paid_at', [$from, $to])
                ->with('client:id,name')->latest('paid_at')->take(10)->get()
                ->map(fn($i) => ['type' => 'income', 'description' => "Invoice {$i->invoice_number} - {$i->client->name}", 'amount' => $i->total, 'date' => $i->paid_at]))
            ->merge($business->expenses()->whereBetween('date', [$from, $to])
                ->latest('date')->take(10)->get()
                ->map(fn($e) => ['type' => 'expense', 'description' => $e->description, 'amount' => $e->amount, 'date' => $e->date]))
            ->sortByDesc('date')->values()->take(15);

        // Previous period (same length) for month-over-month deltas with proper date alignment
        $days = $fromDate->diffInDays($toDate) + 1;
        $prevTo = $fromDate->copy()->subDay()->endOfDay();
        $prevFrom = $fromDate->copy()->subDays($days)->startOfDay();
        $prevIncome = $business->invoices()->where('status', 'paid')
            ->whereBetween('paid_at', [$prevFrom, $prevTo])->sum('total');
        $prevExpenses = $business->expenses()->whereBetween('date', [$prevFrom, $prevTo])->sum('amount');
        $prevProfit = $prevIncome - $prevExpenses;
        $profit = $income - $expenses;
        $incomeChangePct = $prevIncome > 0 ? round((($income - $prevIncome) / $prevIncome) * 100, 1) : ($income > 0 ? 100 : 0);
        $expensesChangePct = $prevExpenses > 0 ? round((($expenses - $prevExpenses) / $prevExpenses) * 100, 1) : ($expenses > 0 ? 100 : 0);
        $profitChangePct = $prevProfit != 0 ? round((($profit - $prevProfit) / abs($prevProfit)) * 100, 1) : ($profit != 0 ? 100 : 0);

        $summary = [
            'income' => $income,
            'expenses' => $expenses,
            'profit' => $profit,
            'outstanding' => $outstanding,
            'previous_income' => $prevIncome,
            'previous_expenses' => $prevExpenses,
            'previous_profit' => $prevProfit,
            'income_change_pct' => $incomeChangePct,
            'expenses_change_pct' => $expensesChangePct,
            'profit_change_pct' => $profitChangePct,
        ];

        // Auto-detect guidance for the user: how income/expenses flow and what to do next
        $guidance = [];
        if ($income <= 0 && $expenses <= 0) {
            $guidance[] = [
                'id' => 'no_data',
                'title' => 'Get started with Accounting',
                'message' => 'Income comes from paid invoices; expenses come from the Expenses page. Create invoices and mark them paid when clients pay, and record expenses to see your profit and loss here.',
                'action_label' => 'Go to Invoices',
                'action_url' => '/dashboard/invoices',
            ];
            $guidance[] = [
                'id' => 'add_expenses',
                'title' => 'Track expenses',
                'message' => 'Record business costs (e.g. software, travel, supplies) in Expenses. They will appear here and in your P&L.',
                'action_label' => 'Add Expense',
                'action_url' => '/dashboard/expenses/create',
            ];
        } elseif ($income > 0 && $expenses <= 0) {
            $guidance[] = [
                'id' => 'add_expenses',
                'title' => 'See your net profit',
                'message' => 'You have income from paid invoices. Record expenses to see net profit and spending by category.',
                'action_label' => 'Add Expense',
                'action_url' => '/dashboard/expenses/create',
            ];
        } elseif ($income <= 0 && $expenses > 0) {
            $guidance[] = [
                'id' => 'add_income',
                'title' => 'Income appears when invoices are paid',
                'message' => 'Send invoices to clients and mark them as paid (or use Paystack) when you receive payment. Income will show here for the period you received payment.',
                'action_label' => 'View Invoices',
                'action_url' => '/dashboard/invoices',
            ];
        }

        return response()->json([
            'summary' => $summary,
            'income_by_client' => $incomeByCategory,
            'expenses_by_category' => $expensesByCategory,
            'pnl' => $pnl,
            'recent_transactions' => $recentTransactions,
            'guidance' => $guidance,
            // Enhanced data for dashboard integration
            'revenue_chart' => $pnl, // Same P&L data for consistency
            'monthly_trends' => [
                'revenue_trend' => array_map(fn($item) => ['month' => $item['month'], 'value' => $item['income']], $pnl),
                'expense_trend' => array_map(fn($item) => ['month' => $item['month'], 'value' => $item['expenses']], $pnl),
                'profit_trend' => array_map(fn($item) => ['month' => $item['month'], 'value' => $item['profit']], $pnl),
            ],
            'top_clients' => $incomeByCategory->take(5),
            'top_expense_categories' => $expensesByCategory->take(5),
            'period_info' => [
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
                'period' => $period,
                'days_in_period' => $fromDate->diffInDays($toDate) + 1,
            ],
        ]);
    }

    public function downloadReceipt(Business $business, Expense $expense)
    {
        if ($expense->business_id !== $business->id) {
            return response()->json(['message' => 'Expense not found'], 404);
        }
        if (!$expense->receipt_path) {
            return response()->json(['error' => 'No receipt found'], 404);
        }

        $filePath = storage_path("app/public/{$expense->receipt_path}");
        
        if (!file_exists($filePath)) {
            return response()->json(['error' => 'Receipt file not found'], 404);
        }

        return response()->download($filePath, "receipt-{$expense->id}.pdf");
    }
}
