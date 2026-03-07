<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\TeamMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class DataExportService
{
    /**
     * Export business data in specified format
     */
    public static function exportBusinessData(Business $business, array $options = []): array
    {
        $format = $options['format'] ?? 'csv';
        $includeArchived = $options['include_archived'] ?? false;
        $dateRange = $options['date_range'] ?? null;
        $entities = $options['entities'] ?? ['invoices', 'clients', 'payments', 'expenses'];

        $data = [];

        foreach ($entities as $entity) {
            switch ($entity) {
                case 'invoices':
                    $data['invoices'] = self::exportInvoices($business, $dateRange, $includeArchived);
                    break;
                case 'clients':
                    $data['clients'] = self::exportClients($business, $includeArchived);
                    break;
                case 'payments':
                    $data['payments'] = self::exportPayments($business, $dateRange);
                    break;
                case 'expenses':
                    $data['expenses'] = self::exportExpenses($business, $dateRange);
                    break;
                case 'contracts':
                    $data['contracts'] = self::exportContracts($business, $includeArchived);
                    break;
                case 'team_members':
                    $data['team_members'] = self::exportTeamMembers($business);
                    break;
            }
        }

        // Add business metadata
        $data['business'] = [
            'name' => $business->name,
            'email' => $business->email,
            'phone' => $business->phone,
            'address' => $business->address,
            'city' => $business->city,
            'country' => $business->country,
            'tin' => $business->tin,
            'registration_number' => $business->registration_number,
            'website' => $business->website,
            'industry' => $business->industry,
            'description' => $business->description,
            'plan' => $business->plan,
            'created_at' => $business->created_at->toDateTimeString(),
            'trial_ends_at' => $business->trial_ends_at?->toDateTimeString(),
            'currency' => $business->currency,
            'payment_verified' => $business->payment_verified,
            'exported_at' => now()->toDateTimeString(),
        ];

        return [
            'format' => $format,
            'business_id' => $business->id,
            'data' => $data,
            'file_info' => [
                'total_records' => self::countTotalRecords($data),
                'file_size' => self::estimateFileSize($data, $format),
                'generated_at' => now()->toDateTimeString(),
            ],
        ];
    }

    /**
     * Export invoices data
     */
    private static function exportInvoices(Business $business, ?array $dateRange = null, bool $includeArchived = false): array
    {
        $query = $business->invoices()
            ->with(['client:id,name,email', 'payments' => function ($query) {
                $query->select('id', 'reference', 'amount', 'status', 'paid_at', 'created_at');
            }])
            ->with(['contract:id,contract_number,type']);

        if ($dateRange && isset($dateRange['from']) && isset($dateRange['to'])) {
            $query->whereBetween('created_at', [$dateRange['from'], $dateRange['to']]);
        }

        if (!$includeArchived) {
            $query->where('status', '!=', 'archived');
        }

        return $query->orderBy('created_at', 'desc')->get()->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'client_name' => $invoice->client->name ?? 'Unknown',
                'client_email' => $invoice->client->email ?? 'unknown@example.com',
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date->toDateString(),
                'status' => $invoice->status,
                'currency' => $invoice->currency,
                'subtotal' => (float) $invoice->subtotal,
                'vat_amount' => (float) $invoice->vat_amount,
                'total' => (float) $invoice->total,
                'amount_paid' => (float) $invoice->amount_paid,
                'amount_due' => (float) ($invoice->total - $invoice->amount_paid),
                'notes' => $invoice->notes,
                'payment_method' => $invoice->payment_method,
                'created_at' => $invoice->created_at->toDateTimeString(),
                'sent_at' => $invoice->sent_at?->toDateTimeString(),
                'paid_at' => $invoice->paid_at?->toDateTimeString(),
                'contract_number' => $invoice->contract?->contract_number,
                'contract_type' => $invoice->contract?->type,
                'payments_count' => $invoice->payments()->count(),
                'total_paid' => (float) $invoice->payments()->where('status', 'success')->sum('amount'),
            ];
        });
    }

    /**
     * Export clients data
     */
    private static function exportClients(Business $business, bool $includeArchived = false): array
    {
        $query = $business->clients();

        if (!$includeArchived) {
            $query->where('status', 'active');
        }

        return $query->orderBy('created_at', 'desc')->get()->map(function ($client) {
            return [
                'id' => $client->id,
                'name' => $client->name,
                'email' => $client->email,
                'phone' => $client->phone,
                'address' => $client->address,
                'city' => $client->city,
                'country' => $client->country,
                'company' => $client->company,
                'status' => $client->status,
                'notes' => $client->notes,
                'created_at' => $client->created_at->toDateTimeString(),
                'invoices_count' => $client->invoices()->count(),
                'total_revenue' => (float) $client->invoices()->where('status', 'paid')->sum('total'),
                'outstanding' => (float) $client->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('total') - (float) $client->invoices()->whereIn('status', ['sent', 'viewed', 'overdue'])->sum('amount_paid'),
                'last_invoice_date' => $client->invoices()->latest('created_at')->first()?->created_at->toDateString(),
            ];
        });
    }

    /**
     * Export payments data
     */
    private static function exportPayments(Business $business, ?array $dateRange = null): array
    {
        $query = $business->payments()
            ->with(['invoice:id,invoice_number', 'client:id,name,email', 'client:id,name,email'])
            ->with(['business:id,name']);

        if ($dateRange) {
            $query->whereBetween('created_at', $dateRange['start'], $dateRange['end']);
        }

        return $query->orderBy('created_at', 'desc')->get()->map(function ($payment) {
            return [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'payment_method' => $payment->method,
                'paid_at' => $payment->paid_at?->toDateTimeString(),
                'created_at' => $payment->created_at->toDateTimeString(),
                'invoice_number' => $payment->invoice?->invoice_number,
                'client_name' => $payment->client?->name ?? 'Unknown',
                'client_email' => $payment->client?->email ?? 'unknown@example.com',
                'business_name' => $payment->business?->name ?? 'Unknown',
                'gateway_reference' => $payment->gateway_reference,
                'metadata' => $payment->metadata,
            ];
        });
    }

    /**
     * Export expenses data
     */
    private static function exportExpenses(Business $business, ?array $dateRange = null): array
    {
        $query = $business->expenses();

        if ($dateRange && isset($dateRange['from']) && isset($dateRange['to'])) {
            $query->whereBetween('date', [$dateRange['from'], $dateRange['to']]);
        }

        return $query->orderBy('date', 'desc')->get()->map(function ($expense) {
            return [
                'id' => $expense->id,
                'reference' => $expense->reference,
                'amount' => (float) $expense->amount,
                'currency' => $expense->currency,
                'category' => $expense->category,
                'description' => $expense->description,
                'date' => $expense->date->toDateString(),
                'receipt_url' => $expense->receipt_url,
                'created_at' => $expense->created_at->toDateTimeString(),
                'updated_at' => $expense->updated_at->toDateTimeString(),
            ];
        })->toArray();
    }

    /**
     * Export contracts data
     */
    private static function exportContracts(Business $business, bool $includeArchived = false): array
    {
        $query = $business->contracts();

        if (!$includeArchived) {
            $query->where('status', '!=', 'archived');
        }

        return $query->orderBy('created_date', 'desc')->get()->map(function ($contract) {
            return [
                'id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'title' => $contract->title,
                'type' => $contract->type,
                'status' => $contract->status,
                'value' => (float) $contract->value,
                'pricing_type' => $contract->pricing_type,
                'created_date' => $contract->created_date->toDateString(),
                'expiry_date' => $contract->expiry_date?->toDateString(),
                'client_name' => $contract->client->name ?? 'Unknown',
                'client_email' => $contract->client->email ?? 'unknown@example.com',
                'created_at' => $contract->created_at->toDateTimeString(),
                'updated_at' => $contract->updated_at->toDateTimeString(),
            ];
        });
    }

    /**
     * Export team members data
     */
    private static function exportTeamMembers(Business $business): array
    {
        return $business->teamMembers()
            ->with(['user:id,name,email,avatar', 'status'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($member) {
                return [
                    'id' => $member->id,
                    'name' => $member->user->name,
                    'email' => $member->user->email,
                    'role' => $member->role,
                    'status' => $member->status,
                    'joined_at' => $member->created_at->toDateTimeString(),
                ];
            });
    }

    /**
     * Generate export file and return download URL
     */
    public static function generateExportFile(array $exportData): string
    {
        $format = $exportData['format'];
        $businessId = $exportData['business_id'];
        $data = $exportData['data'];
        $filename = "business_{$businessId}_export_" . now()->format('Y-m-d_H-i-s') . ".{$format}";

        $content = match ($format) {
            'csv' => self::generateCsv($data),
            'excel' => self::generateExcel($data),
            'json' => json_encode($data, JSON_PRETTY_PRINT),
            default => throw new \Exception("Unsupported export format: {$format}")
        };

        $path = "exports/{$filename}";
        Storage::put($path, $content);

        return Storage::url($path);
    }

    /**
     * Generate CSV format
     */
    private static function generateCsv(array $data): string
    {
        $csv = '';
        $headers = [];

        // Extract headers from first record
        if (!empty($data)) {
            $firstRecord = $data[array_key_first($data)];
            $headers = array_keys($firstRecord);
            $csv .= implode(',', array_map(function($header) {
                return ucfirst($header);
            }, $headers)) . PHP_EOL;
        }

        foreach ($data as $row) {
            $csv .= implode(',', array_map(function($value) {
                return is_string($value) ? "\"{$value}\"" : $value;
            }, $row));
            $csv .= PHP_EOL;
        }

        return $csv;
    }

    /**
     * Generate Excel format (basic implementation)
     */
    private static function generateExcel(array $data): string
    {
        // For now, return CSV format as Excel fallback
        return self::generateCsv($data);
    }

    /**
     * Count total records in export data
     */
    private static function countTotalRecords(array $data): int
    {
        $total = 0;
        foreach ($data as $entityData) {
            $total += is_array($entityData) ? count($entityData) : 1;
        }
        return $total;
    }

    /**
     * Estimate file size
     */
    private static function estimateFileSize(array $data, string $format): int
    {
        $content = match ($format) {
            'csv' => self::generateCsv($data),
            'excel' => self::generateExcel($data),
            'json' => json_encode($data),
            default => 0,
        };

        return strlen($content);
    }
}
