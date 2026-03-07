<?php

namespace App\Jobs;

use App\Services\DataRetentionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DataRetentionCleanup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting data retention cleanup job');
            
            DataRetentionService::scheduleRetentionCleanup();
            
            Log::info('Data retention cleanup job completed successfully');
            
        } catch (\Exception $e) {
            Log::error('Data retention cleanup job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-queue the job if it fails
            $this->release(300); // Release for 5 minutes
        }
    }
}
