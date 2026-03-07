<?php

namespace App\Services;

use App\Models\Business;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\TeamMember;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class DataRetentionService
{
    /**
     * Apply data retention policies for a business
     */
    public static function applyRetentionPolicies(Business $business): array
    {
        $policies = self::getRetentionPolicies($business);
        $results = [];

        foreach ($policies as $entity => $policy) {
            if ($policy['enabled']) {
                $results[$entity] = self::applyEntityPolicy($business, $entity, $policy);
            }
        }

        // Log retention policy application
        ActivityLog::log('data.retention.applied', 
            "Data retention policies applied: " . json_encode(array_keys($results)), 
            $business,
            ['policies_applied' => array_keys($results)]
        );

        return $results;
    }

    /**
     * Get retention policies for a business
     */
    public static function getRetentionPolicies(Business $business): array
    {
        $plan = $business->plan ?? 'free';
        $customPolicies = $business->data_retention_policies ?? [];

        $defaultPolicies = [
            'invoices' => [
                'enabled' => true,
                'retention_days' => 2555, // 7 years for accounting
                'keep_paid' => true,
                'keep_active' => true,
                'archive_after_days' => 365,
            ],
            'payments' => [
                'enabled' => true,
                'retention_days' => 2555, // 7 years for accounting
                'keep_successful' => true,
                'archive_after_days' => 365,
            ],
            'expenses' => [
                'enabled' => true,
                'retention_days' => 2555, // 7 years for accounting
                'archive_after_days' => 365,
            ],
            'activity_logs' => [
                'enabled' => true,
                'retention_days' => 365, // 1 year for audit trail
                'keep_critical' => true,
                'critical_actions' => ['business.deleted', 'business.force_deleted', 'data.exported'],
            ],
            'team_members' => [
                'enabled' => true,
                'retention_days' => 0, // Keep indefinitely for inactive
                'keep_active' => true,
                'archive_inactive_after_days' => 730, // 2 years
            ],
        ];

        // Override with custom policies if set
        foreach ($defaultPolicies as $entity => $policy) {
            if (isset($customPolicies[$entity])) {
                $defaultPolicies[$entity] = array_merge($policy, $customPolicies[$entity]);
            }
        }

        return $defaultPolicies;
    }

    /**
     * Apply retention policy for a specific entity
     */
    private static function applyEntityPolicy(Business $business, string $entity, array $policy): array
    {
        $cutoffDate = now()->subDays($policy['retention_days']);
        $result = [
            'entity' => $entity,
            'policy_applied' => true,
            'records_processed' => 0,
            'records_archived' => 0,
            'records_deleted' => 0,
            'errors' => [],
        ];

        try {
            switch ($entity) {
                case 'invoices':
                    $result = self::applyInvoicePolicy($business, $policy, $cutoffDate, $result);
                    break;
                case 'payments':
                    $result = self::applyPaymentPolicy($business, $policy, $cutoffDate, $result);
                    break;
                case 'expenses':
                    $result = self::applyExpensePolicy($business, $policy, $cutoffDate, $result);
                    break;
                case 'activity_logs':
                    $result = self::applyActivityLogPolicy($business, $policy, $cutoffDate, $result);
                    break;
                case 'team_members':
                    $result = self::applyTeamMemberPolicy($business, $policy, $cutoffDate, $result);
                    break;
            }
        } catch (\Exception $e) {
            Log::error("Failed to apply retention policy for {$entity}", [
                'business_id' => $business->id,
                'entity' => $entity,
                'error' => $e->getMessage()
            ]);
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Apply invoice retention policy
     */
    private static function applyInvoicePolicy(Business $business, array $policy, Carbon $cutoffDate, array $result): array
    {
        $query = $business->invoices()->where('created_at', '<', $cutoffDate);

        // Exclude invoices that should be kept
        if ($policy['keep_paid']) {
            $query->where('status', '!=', 'paid');
        }

        if ($policy['keep_active']) {
            $query->whereIn('status', ['sent', 'viewed', 'overdue']);
        }

        $records = $query->get();
        $result['records_processed'] = $records->count();

        foreach ($records as $invoice) {
            if ($policy['archive_after_days'] && $invoice->created_at->diffInDays(now()) > $policy['archive_after_days']) {
                // Archive the invoice
                self::archiveInvoice($invoice);
                $result['records_archived']++;
            } else {
                // Delete the invoice
                $invoice->delete();
                $result['records_deleted']++;
            }
        }

        return $result;
    }

    /**
     * Apply payment retention policy
     */
    private static function applyPaymentPolicy(Business $business, array $policy, Carbon $cutoffDate, array $result): array
    {
        $query = $business->payments()->where('created_at', '<', $cutoffDate);

        // Exclude payments that should be kept
        if ($policy['keep_successful']) {
            $query->where('status', '!=', 'success');
        }

        $records = $query->get();
        $result['records_processed'] = $records->count();

        foreach ($records as $payment) {
            if ($policy['archive_after_days'] && $payment->created_at->diffInDays(now()) > $policy['archive_after_days']) {
                // Archive the payment
                self::archivePayment($payment);
                $result['records_archived']++;
            } else {
                // Delete the payment
                $payment->delete();
                $result['records_deleted']++;
            }
        }

        return $result;
    }

    /**
     * Apply expense retention policy
     */
    private static function applyExpensePolicy(Business $business, array $policy, Carbon $cutoffDate, array $result): array
    {
        $records = $business->expenses()->where('date', '<', $cutoffDate)->get();
        $result['records_processed'] = $records->count();

        foreach ($records as $expense) {
            if ($policy['archive_after_days'] && $expense->date->diffInDays(now()) > $policy['archive_after_days']) {
                // Archive the expense
                self::archiveExpense($expense);
                $result['records_archived']++;
            } else {
                // Delete the expense
                $expense->delete();
                $result['records_deleted']++;
            }
        }

        return $result;
    }

    /**
     * Apply activity log retention policy
     */
    private static function applyActivityLogPolicy(Business $business, array $policy, Carbon $cutoffDate, array $result): array
    {
        $query = $business->activityLogs()->where('created_at', '<', $cutoffDate);

        // Exclude critical actions
        if ($policy['keep_critical'] && !empty($policy['critical_actions'])) {
            $query->whereNotIn('action', $policy['critical_actions']);
        }

        $records = $query->get();
        $result['records_processed'] = $records->count();

        foreach ($records as $log) {
            // Archive activity logs (don't delete for audit trail)
            self::archiveActivityLog($log);
            $result['records_archived']++;
        }

        return $result;
    }

    /**
     * Apply team member retention policy
     */
    private static function applyTeamMemberPolicy(Business $business, array $policy, Carbon $cutoffDate, array $result): array
    {
        $records = $business->teamMembers()
            ->where('status', 'inactive')
            ->where('updated_at', '<', $cutoffDate)
            ->get();

        $result['records_processed'] = $records->count();

        foreach ($records as $member) {
            if ($policy['archive_inactive_after_days'] && $member->updated_at->diffInDays(now()) > $policy['archive_inactive_after_days']) {
                // Archive the team member
                self::archiveTeamMember($member);
                $result['records_archived']++;
            }
        }

        return $result;
    }

    /**
     * Archive invoice
     */
    private static function archiveInvoice(Invoice $invoice): void
    {
        $archiveData = [
            'table' => 'invoices',
            'data' => $invoice->toArray(),
            'archived_at' => now()->toDateTimeString(),
            'archived_reason' => 'retention_policy'
        ];

        Storage::put("archives/invoices/{$invoice->id}.json", json_encode($archiveData, JSON_PRETTY_PRINT));
        
        ActivityLog::log('invoice.archived', 
            "Invoice {$invoice->invoice_number} archived due to retention policy", 
            $invoice
        );
    }

    /**
     * Archive payment
     */
    private static function archivePayment(Payment $payment): void
    {
        $archiveData = [
            'table' => 'payments',
            'data' => $payment->toArray(),
            'archived_at' => now()->toDateTimeString(),
            'archived_reason' => 'retention_policy'
        ];

        Storage::put("archives/payments/{$payment->id}.json", json_encode($archiveData, JSON_PRETTY_PRINT));
    }

    /**
     * Archive expense
     */
    private static function archiveExpense($expense): void
    {
        $archiveData = [
            'table' => 'expenses',
            'data' => $expense->toArray(),
            'archived_at' => now()->toDateTimeString(),
            'archived_reason' => 'retention_policy'
        ];

        Storage::put("archives/expenses/{$expense->id}.json", json_encode($archiveData, JSON_PRETTY_PRINT));
    }

    /**
     * Archive activity log
     */
    private static function archiveActivityLog(ActivityLog $log): void
    {
        $archiveData = [
            'table' => 'activity_logs',
            'data' => $log->toArray(),
            'archived_at' => now()->toDateTimeString(),
            'archived_reason' => 'retention_policy'
        ];

        Storage::put("archives/activity_logs/{$log->id}.json", json_encode($archiveData, JSON_PRETTY_PRINT));
    }

    /**
     * Archive team member
     */
    private static function archiveTeamMember(TeamMember $member): void
    {
        $archiveData = [
            'table' => 'team_members',
            'data' => $member->toArray(),
            'archived_at' => now()->toDateTimeString(),
            'archived_reason' => 'retention_policy'
        ];

        Storage::put("archives/team_members/{$member->id}.json", json_encode($archiveData, JSON_PRETTY_PRINT));
    }

    /**
     * Schedule automatic retention policy application
     */
    public static function scheduleRetentionCleanup(): void
    {
        $businesses = Business::where('status', 'active')->get();
        
        foreach ($businesses as $business) {
            try {
                self::applyRetentionPolicies($business);
            } catch (\Exception $e) {
                Log::error("Failed to apply retention policies for business {$business->id}", [
                    'business_id' => $business->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Get retention policy summary for a business
     */
    public static function getRetentionSummary(Business $business): array
    {
        $policies = self::getRetentionPolicies($business);
        $summary = [];

        foreach ($policies as $entity => $policy) {
            if ($policy['enabled']) {
                $summary[$entity] = [
                    'enabled' => $policy['enabled'],
                    'retention_days' => $policy['retention_days'],
                    'archive_after_days' => $policy['archive_after_days'] ?? null,
                    'keep_conditions' => array_filter([
                        'keep_paid' => $policy['keep_paid'] ?? null,
                        'keep_active' => $policy['keep_active'] ?? null,
                        'keep_successful' => $policy['keep_successful'] ?? null,
                        'keep_critical' => $policy['keep_critical'] ?? null,
                    ]),
                ];
            }
        }

        return $summary;
    }
}
