<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\Client;
use App\Models\TeamMember;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ResourceMonitoringService
{
    /**
     * Get comprehensive resource usage metrics for a business
     */
    public static function getResourceMetrics(Business $business): array
    {
        $cacheKey = "resource_metrics_{$business->id}";
        
        return Cache::remember($cacheKey, 300, function () use ($business) {
            return [
                'business_id' => $business->id,
                'business_name' => $business->name,
                'plan' => $business->plan,
                'metrics' => [
                    'invoices' => self::getInvoiceMetrics($business),
                    'clients' => self::getClientMetrics($business),
                    'payments' => self::getPaymentMetrics($business),
                    'expenses' => self::getExpenseMetrics($business),
                    'team_members' => self::getTeamMemberMetrics($business),
                    'storage' => self::getStorageMetrics($business),
                    'database' => self::getDatabaseMetrics($business),
                ],
                'alerts' => self::checkResourceAlerts($business),
                'generated_at' => now()->toDateTimeString(),
            ];
        });
    }

    /**
     * Get invoice-related metrics
     */
    private static function getInvoiceMetrics(Business $business): array
    {
        $now = now();
        $last30Days = $now->copy()->subDays(30);
        $last90Days = $now->copy()->subDays(90);

        return [
            'total' => $business->invoices()->count(),
            'last_30_days' => $business->invoices()->where('created_at', '>=', $last30Days)->count(),
            'last_90_days' => $business->invoices()->where('created_at', '>=', $last90Days)->count(),
            'by_status' => [
                'draft' => $business->invoices()->where('status', 'draft')->count(),
                'sent' => $business->invoices()->where('status', 'sent')->count(),
                'viewed' => $business->invoices()->where('status', 'viewed')->count(),
                'paid' => $business->invoices()->where('status', 'paid')->count(),
                'partial' => $business->invoices()->where('status', 'partial')->count(),
                'overdue' => $business->invoices()->where('status', 'overdue')->count(),
            ],
            'total_value' => (float) $business->invoices()->sum('total'),
            'paid_value' => (float) $business->invoices()->where('status', 'paid')->sum('total'),
            'outstanding_value' => (float) $business->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('total') - (float) $business->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('amount_paid'),
            'average_value' => $business->invoices()->avg('total') ?? 0,
            'growth_rate' => self::calculateGrowthRate($business->invoices(), 'created_at', 30),
        ];
    }

    /**
     * Get client-related metrics
     */
    private static function getClientMetrics(Business $business): array
    {
        return [
            'total' => $business->clients()->count(),
            'active' => $business->clients()->where('status', 'active')->count(),
            'inactive' => $business->clients()->where('status', 'inactive')->count(),
            'with_invoices' => $business->clients()->whereHas('invoices')->count(),
            'total_revenue' => (float) $business->clients()->join('invoices', 'invoices.client_id', '=', 'clients.id')->where('invoices.status', 'paid')->sum('invoices.total'),
            'average_revenue_per_client' => $business->clients()->join('invoices', 'invoices.client_id', '=', 'clients.id')->where('invoices.status', 'paid')->avg('invoices.total') ?? 0,
            'new_this_month' => $business->clients()->whereMonth('created_at', now()->month)->count(),
        ];
    }

    /**
     * Get payment-related metrics
     */
    private static function getPaymentMetrics(Business $business): array
    {
        $now = now();
        $last30Days = $now->copy()->subDays(30);

        return [
            'total' => $business->payments()->count(),
            'last_30_days' => $business->payments()->where('created_at', '>=', $last30Days)->count(),
            'by_status' => [
                'success' => $business->payments()->where('status', 'success')->count(),
                'pending' => $business->payments()->where('status', 'pending')->count(),
                'failed' => $business->payments()->where('status', 'failed')->count(),
            ],
            'total_amount' => (float) $business->payments()->sum('amount'),
            'successful_amount' => (float) $business->payments()->where('status', 'success')->sum('amount'),
            'average_amount' => $business->payments()->avg('amount') ?? 0,
            'success_rate' => self::calculateSuccessRate($business->payments()),
            'by_method' => self::getPaymentMethodBreakdown($business),
        ];
    }

    /**
     * Get expense-related metrics
     */
    private static function getExpenseMetrics(Business $business): array
    {
        $now = now();
        $last30Days = $now->copy()->subDays(30);

        return [
            'total' => $business->expenses()->count(),
            'last_30_days' => $business->expenses()->where('date', '>=', $last30Days)->count(),
            'total_amount' => (float) $business->expenses()->sum('amount'),
            'last_30_days_amount' => (float) $business->expenses()->where('date', '>=', $last30Days)->sum('amount'),
            'average_amount' => $business->expenses()->avg('amount') ?? 0,
            'by_category' => self::getExpenseCategoryBreakdown($business),
            'this_month' => (float) $business->expenses()->whereMonth('date', now()->month)->sum('amount'),
            'last_month' => (float) $business->expenses()->whereMonth('date', now()->copy()->subMonth()->month)->sum('amount'),
        ];
    }

    /**
     * Get team member metrics
     */
    private static function getTeamMemberMetrics(Business $business): array
    {
        return [
            'total' => $business->teamMembers()->count(),
            'by_role' => [
                'admin' => $business->teamMembers()->where('role', 'admin')->count(),
                'accountant' => $business->teamMembers()->where('role', 'accountant')->count(),
                'staff' => $business->teamMembers()->where('role', 'staff')->count(),
                'viewer' => $business->teamMembers()->where('role', 'viewer')->count(),
            ],
            'by_status' => [
                'active' => $business->teamMembers()->where('status', 'active')->count(),
                'inactive' => $business->teamMembers()->where('status', 'inactive')->count(),
                'pending' => $business->teamMembers()->where('status', 'pending')->count(),
            ],
            'invitations_sent' => $business->teamMembers()->where('status', 'pending')->count(),
        ];
    }

    /**
     * Get storage metrics
     */
    private static function getStorageMetrics(Business $business): array
    {
        $storageUsed = $business->getStorageUsed();
        $planLimits = $business->getPlanLimits();
        $storageLimit = $planLimits['storage_gb'] ?? 1;

        return [
            'used_gb' => round($storageUsed, 3),
            'limit_gb' => $storageLimit,
            'usage_percentage' => $storageLimit > 0 ? round(($storageUsed / $storageLimit) * 100, 2) : 0,
            'available_gb' => max(0, $storageLimit - $storageUsed),
            'file_count' => self::countBusinessFiles($business),
        ];
    }

    /**
     * Get database metrics
     */
    private static function getDatabaseMetrics(Business $business): array
    {
        $tables = [
            'invoices' => $business->invoices()->count(),
            'clients' => $business->clients()->count(),
            'payments' => $business->payments()->count(),
            'expenses' => $business->expenses()->count(),
            'contracts' => $business->contracts()->count(),
            'team_members' => $business->teamMembers()->count(),
            'activity_logs' => $business->activityLogs()->count(),
        ];

        return [
            'total_records' => array_sum($tables),
            'table_breakdown' => $tables,
            'estimated_size_mb' => self::estimateDatabaseSize($business),
        ];
    }

    /**
     * Check for resource alerts
     */
    private static function checkResourceAlerts(Business $business): array
    {
        $alerts = [];
        $planLimits = $business->getPlanLimits();

        // Check storage usage
        $storageUsed = $business->getStorageUsed();
        $storageLimit = $planLimits['storage_gb'] ?? 1;
        if ($storageUsed / $storageLimit > 0.9) {
            $alerts[] = [
                'type' => 'storage',
                'severity' => $storageUsed / $storageLimit > 0.95 ? 'critical' : 'warning',
                'message' => 'Storage usage is ' . round(($storageUsed / $storageLimit) * 100, 1) . '% of limit',
                'usage_percentage' => round(($storageUsed / $storageLimit) * 100, 1),
            ];
        }

        // Check team member limits
        $teamCount = $business->teamMembers()->count();
        $teamLimit = $planLimits['team_members'] ?? $planLimits['users'] ?? 1;
        if ($teamCount >= $teamLimit) {
            $alerts[] = [
                'type' => 'team_members',
                'severity' => 'warning',
                'message' => 'Team member limit reached',
                'current' => $teamCount,
                'limit' => $teamLimit,
            ];
        }

        // Check client limits
        $clientCount = $business->clients()->count();
        $clientLimit = $planLimits['clients'] ?? 5;
        if ($clientCount >= $clientLimit) {
            $alerts[] = [
                'type' => 'clients',
                'severity' => 'warning',
                'message' => 'Client limit reached',
                'current' => $clientCount,
                'limit' => $clientLimit,
            ];
        }

        // Check failed payments
        $failedPayments = $business->payments()->where('status', 'failed')->count();
        if ($failedPayments > 5) {
            $alerts[] = [
                'type' => 'payments',
                'severity' => 'warning',
                'message' => 'High number of failed payments detected',
                'count' => $failedPayments,
            ];
        }

        return $alerts;
    }

    /**
     * Calculate growth rate
     */
    private static function calculateGrowthRate($query, string $dateField, int $days): float
    {
        $now = now();
        $previousPeriod = $now->copy()->subDays($days * 2);
        $currentPeriod = $now->copy()->subDays($days);

        $previousCount = $query->where($dateField, '>=', $previousPeriod)->where($dateField, '<', $currentPeriod)->count();
        $currentCount = $query->where($dateField, '>=', $currentPeriod)->count();

        if ($previousCount == 0) {
            return $currentCount > 0 ? 100 : 0;
        }

        return round((($currentCount - $previousCount) / $previousCount) * 100, 2);
    }

    /**
     * Calculate success rate
     */
    private static function calculateSuccessRate($query): float
    {
        $total = $query->count();
        $successful = $query->where('status', 'success')->count();

        if ($total == 0) {
            return 0;
        }

        return round(($successful / $total) * 100, 2);
    }

    /**
     * Get payment method breakdown
     */
    private static function getPaymentMethodBreakdown(Business $business): array
    {
        return $business->payments()
            ->selectRaw('method, COUNT(*) as count, SUM(amount) as total')
            ->where('status', 'success')
            ->groupBy('method')
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    'count' => $item->count,
                    'total' => (float) $item->total,
                ];
            })
            ->toArray();
    }

    /**
     * Get expense category breakdown
     */
    private static function getExpenseCategoryBreakdown(Business $business): array
    {
        return $business->expenses()
            ->selectRaw('category, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('category')
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    'count' => $item->count,
                    'total' => (float) $item->total,
                ];
            })
            ->toArray();
    }

    /**
     * Count business files
     */
    private static function countBusinessFiles(Business $business): int
    {
        $count = 0;
        
        // Count logos
        if ($business->logo) {
            $count++;
        }
        
        // Count signature images
        if ($business->signature_image) {
            $count++;
        }
        
        // Count expense receipts
        $count += $business->expenses()->whereNotNull('receipt_url')->count();
        
        return $count;
    }

    /**
     * Estimate database size for business
     */
    private static function estimateDatabaseSize(Business $business): float
    {
        // This is a rough estimation based on average record sizes
        $sizes = [
            'invoices' => 2048, // ~2KB per invoice
            'clients' => 512,    // ~512B per client
            'payments' => 1024, // ~1KB per payment
            'expenses' => 1024, // ~1KB per expense
            'contracts' => 4096, // ~4KB per contract
            'team_members' => 512, // ~512B per team member
            'activity_logs' => 256, // ~256B per log entry
        ];

        $totalSize = 0;
        foreach ($sizes as $table => $size) {
            $method = 'get' . ucfirst($table);
            if (method_exists($business, $method)) {
                $totalSize += $business->$method()->count() * $size;
            }
        }

        return round($totalSize / 1024 / 1024, 2); // Convert to MB
    }

    /**
     * Get system-wide resource metrics (for admin)
     */
    public static function getSystemMetrics(): array
    {
        return Cache::remember('system_resource_metrics', 600, function () {
            return [
                'total_businesses' => Business::count(),
                'active_businesses' => Business::where('status', 'active')->count(),
                'total_invoices' => Invoice::count(),
                'total_clients' => Client::count(),
                'total_payments' => Payment::count(),
                'total_expenses' => Expense::count(),
                'total_team_members' => TeamMember::count(),
                'database_size_mb' => self::getTotalDatabaseSize(),
                'storage_usage_gb' => self::getTotalStorageUsage(),
                'system_load' => self::getSystemLoad(),
                'generated_at' => now()->toDateTimeString(),
            ];
        });
    }

    /**
     * Get total database size
     */
    private static function getTotalDatabaseSize(): float
    {
        try {
            $result = DB::select('SUM(data_length + index_length) as size')
                ->from('information_schema.tables')
                ->where('table_schema', config('database.connections.mysql.database'))
                ->first();

            return round($result->size / 1024 / 1024, 2); // Convert to MB
        } catch (\Exception $e) {
            Log::error('Failed to get database size', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get total storage usage
     */
    private static function getTotalStorageUsage(): float
    {
        try {
            $storagePath = storage_path('app');
            $totalSize = 0;

            if (is_dir($storagePath)) {
                $totalSize = self::calculateDirectorySize($storagePath);
            }

            return round($totalSize / 1024 / 1024 / 1024, 2); // Convert to GB
        } catch (\Exception $e) {
            Log::error('Failed to get storage usage', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Get system load
     */
    private static function getSystemLoad(): array
    {
        try {
            $load = sys_getloadavg();
            return [
                'load_1min' => $load[0] ?? 0,
                'load_5min' => $load[1] ?? 0,
                'load_15min' => $load[2] ?? 0,
            ];
        } catch (\Exception $e) {
            return [
                'load_1min' => 0,
                'load_5min' => 0,
                'load_15min' => 0,
            ];
        }
    }

    /**
     * Calculate directory size recursively
     */
    private static function calculateDirectorySize(string $dir): int
    {
        $size = 0;
        $files = scandir($dir);

        foreach ($files as $file) {
            if ($file[0] === '.') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $size += self::calculateDirectorySize($path);
            } else {
                $size += filesize($path);
            }
        }

        return $size;
    }
}
