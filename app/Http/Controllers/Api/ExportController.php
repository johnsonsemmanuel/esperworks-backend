<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Services\DataExportService;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExportController extends Controller
{
    /**
     * Get available export options
     */
    public function options(Business $business)
    {
        $this->authorize('view', $business);

        return response()->json([
            'formats' => ['csv', 'excel', 'json'],
            'entities' => [
                'invoices' => 'Invoices',
                'clients' => 'Clients', 
                'payments' => 'Payments',
                'expenses' => 'Expenses',
                'contracts' => 'Contracts',
                'team_members' => 'Team Members'
            ],
            'date_ranges' => [
                'last_30_days' => 'Last 30 Days',
                'last_90_days' => 'Last 90 Days',
                'last_year' => 'Last Year',
                'all_time' => 'All Time',
                'custom' => 'Custom Range'
            ],
            'limits' => [
                'max_records_per_export' => 10000,
                'max_file_size_mb' => 50,
                'retention_days' => 7
            ]
        ]);
    }

    /**
     * Initiate data export
     */
    public function export(Request $request, Business $business)
    {
        $this->authorize('view', $business);

        $request->validate([
            'format' => 'required|in:csv,excel,json',
            'entities' => 'required|array',
            'entities.*' => 'in:invoices,clients,payments,expenses,contracts,team_members',
            'date_range' => 'nullable|array',
            'date_range.start' => 'required_with:date_range|date',
            'date_range.end' => 'required_with:date_range|date|after_or_equal:date_range.start',
            'include_archived' => 'boolean',
        ]);

        // Check if export is allowed based on plan limits
        if (!$this->canExport($business)) {
            return response()->json([
                'message' => 'Data export is not available on your current plan. Please upgrade to access this feature.',
                'upgrade_required' => true,
                'plan' => $business->plan,
            ], 403);
        }

        // Check for existing exports (rate limiting)
        $recentExports = $this->getRecentExports($business);
        if (count($recentExports) >= 5) {
            return response()->json([
                'message' => 'You have reached the export limit. Please try again later.',
                'retry_after' => 3600, // 1 hour
            ], 429);
        }

        try {
            $exportData = DataExportService::exportBusinessData($business, $request->all());
            
            // Check file size limits
            if ($exportData['file_info']['file_size'] > 50 * 1024 * 1024) { // 50MB
                return response()->json([
                    'message' => 'Export is too large. Please reduce the date range or select fewer entities.',
                    'file_size_mb' => round($exportData['file_info']['file_size'] / 1024 / 1024, 2),
                    'max_size_mb' => 50
                ], 422);
            }

            // Generate file
            $fileUrl = DataExportService::generateExportFile($exportData);

            // Log the export
            ActivityLog::log('data.exported', 
                "Business data exported: {$exportData['file_info']['total_records']} records in {$exportData['format']} format", 
                $business,
                ['format' => $exportData['format'], 'entities' => $request->entities, 'records' => $exportData['file_info']['total_records']]
            );

            return response()->json([
                'message' => 'Export generated successfully',
                'download_url' => $fileUrl,
                'file_info' => $exportData['file_info'],
                'expires_at' => now()->addDays(7)->toDateTimeString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Data export failed', [
                'business_id' => $business->id,
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'message' => 'Export failed. Please try again or contact support.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get export history
     */
    public function history(Business $business)
    {
        $this->authorize('view', $business);

        $exports = ActivityLog::where('business_id', $business->id)
            ->where('action', 'data.exported')
            ->latest()
            ->take(20)
            ->get()
            ->map(function ($log) {
                $data = $log->data ?? [];
                return [
                    'id' => $log->id,
                    'format' => $data['format'] ?? 'unknown',
                    'entities' => $data['entities'] ?? [],
                    'records' => $data['records'] ?? 0,
                    'created_at' => $log->created_at->toDateTimeString(),
                    'description' => $log->description,
                ];
            });

        return response()->json(['exports' => $exports]);
    }

    /**
     * Download export file
     */
    public function download(Business $business, $filename)
    {
        $this->authorize('view', $business);

        $path = "exports/{$filename}";
        
        if (!Storage::exists($path)) {
            return response()->json(['message' => 'Export file not found or has expired'], 404);
        }

        // Verify file belongs to this business
        if (!$this->isExportFileOwnedByBusiness($filename, $business)) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        return Storage::download($path, $filename);
    }

    /**
     * Delete export file
     */
    public function delete(Business $business, $filename)
    {
        $this->authorize('update', $business);

        $path = "exports/{$filename}";
        
        if (!Storage::exists($path)) {
            return response()->json(['message' => 'Export file not found'], 404);
        }

        // Verify file belongs to this business
        if (!$this->isExportFileOwnedByBusiness($filename, $business)) {
            return response()->json(['message' => 'Access denied'], 403);
        }

        Storage::delete($path);

        return response()->json(['message' => 'Export file deleted successfully']);
    }

    /**
     * Check if business can export based on plan
     */
    private function canExport(Business $business): bool
    {
        $plan = $business->plan ?? 'free';
        
        // All plans except free can export
        return $plan !== 'free';
    }

    /**
     * Get recent exports for rate limiting
     */
    private function getRecentExports(Business $business, int $hours = 24): array
    {
        return ActivityLog::where('business_id', $business->id)
            ->where('action', 'data.exported')
            ->where('created_at', '>=', now()->subHours($hours))
            ->pluck('created_at')
            ->toArray();
    }

    /**
     * Verify export file ownership
     */
    private function isExportFileOwnedByBusiness(string $filename, Business $business): bool
    {
        // Extract business ID from filename format: business_{id}_export_{timestamp}.format
        if (preg_match('/business_(\d+)_export/', $filename, $matches)) {
            return (int) $matches[1] === $business->id;
        }

        return false;
    }
}
