<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Carbon\Carbon;

class QueueMonitoringService
{
    /**
     * Default monitoring thresholds
     */
    const DEFAULT_THRESHOLDS = [
        'max_queue_size' => 1000,
        'max_processing_time' => 300, // 5 minutes
        'max_failure_rate' => 0.1, // 10%
        'alert_threshold' => 0.8, // 80% of max
    ];

    /**
     * Get comprehensive queue statistics
     */
    public static function getQueueStats(): array
    {
        $stats = [];
        $queues = config('queue.connections.database.queue', ['default']);

        foreach ($queues as $queue) {
            $stats[$queue] = self::getQueueStatsByName($queue);
        }

        return [
            'queues' => $stats,
            'total_jobs' => array_sum(array_column($stats, 'total_jobs')),
            'total_failed' => array_sum(array_column($stats, 'failed_jobs')),
            'total_processing' => array_sum(array_column($stats, 'processing_jobs')),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Get statistics for a specific queue
     */
    public static function getQueueStatsByName(string $queue): array
    {
        // Get job counts from database
        $totalJobs = DB::table('jobs')
            ->where('queue', $queue)
            ->count();

        $failedJobs = DB::table('failed_jobs')
            ->where('queue', $queue)
            ->count();

        // Get processing jobs (jobs that have been reserved but not completed)
        $processingJobs = DB::table('jobs')
            ->where('queue', $queue)
            ->whereNotNull('reserved_at')
            ->count();

        // Get recent job performance
        $recentJobs = self::getRecentJobPerformance($queue, 60); // Last hour

        // Calculate metrics
        $avgProcessingTime = $recentJobs['avg_processing_time'] ?? 0;
        $failureRate = $recentJobs['total_completed'] > 0 
            ? $recentJobs['failed_count'] / $recentJobs['total_completed'] 
            : 0;

        // Get oldest job age
        $oldestJobAge = self::getOldestJobAge($queue);

        // Check if queue is healthy
        $isHealthy = self::isQueueHealthy([
            'total_jobs' => $totalJobs,
            'failed_jobs' => $failedJobs,
            'avg_processing_time' => $avgProcessingTime,
            'failure_rate' => $failureRate,
            'oldest_job_age' => $oldestJobAge,
        ]);

        return [
            'queue_name' => $queue,
            'total_jobs' => $totalJobs,
            'failed_jobs' => $failedJobs,
            'processing_jobs' => $processingJobs,
            'pending_jobs' => $totalJobs - $processingJobs,
            'avg_processing_time' => round($avgProcessingTime, 2),
            'failure_rate' => round($failureRate * 100, 2),
            'oldest_job_age' => $oldestJobAge,
            'is_healthy' => $isHealthy,
            'recent_performance' => $recentJobs,
            'last_checked' => now()->toDateTimeString(),
        ];
    }

    /**
     * Get recent job performance metrics
     */
    private static function getRecentJobPerformance(string $queue, int $minutes = 60): array
    {
        $since = now()->subMinutes($minutes);

        // This is a simplified version - in production you'd want to track job completion times
        // For now, we'll use failed_jobs as a proxy for performance issues
        $failedCount = DB::table('failed_jobs')
            ->where('queue', $queue)
            ->where('failed_at', '>=', $since)
            ->count();

        // Estimate completed jobs (this would need proper job tracking in production)
        $completedCount = max(0, $failedCount * 9); // Assume 90% success rate for estimation

        return [
            'period_minutes' => $minutes,
            'completed_count' => $completedCount,
            'failed_count' => $failedCount,
            'total_completed' => $completedCount + $failedCount,
            'avg_processing_time' => rand(10, 60), // Placeholder - would need actual tracking
        ];
    }

    /**
     * Get the age of the oldest job in the queue
     */
    private static function getOldestJobAge(string $queue): int
    {
        $oldestJob = DB::table('jobs')
            ->where('queue', $queue)
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$oldestJob) {
            return 0;
        }

        return now()->diffInSeconds(Carbon::parse($oldestJob->created_at));
    }

    /**
     * Check if a queue is healthy based on thresholds
     */
    private static function isQueueHealthy(array $stats): bool
    {
        $thresholds = self::DEFAULT_THRESHOLDS;

        // Check queue size
        if ($stats['total_jobs'] > $thresholds['max_queue_size']) {
            return false;
        }

        // Check failure rate
        if ($stats['failure_rate'] > $thresholds['max_failure_rate']) {
            return false;
        }

        // Check processing time
        if ($stats['avg_processing_time'] > $thresholds['max_processing_time']) {
            return false;
        }

        // Check oldest job age (shouldn't be older than 30 minutes)
        if ($stats['oldest_job_age'] > 1800) {
            return false;
        }

        return true;
    }

    /**
     * Get queue health alerts
     */
    public static function getHealthAlerts(): array
    {
        $alerts = [];
        $stats = self::getQueueStats();

        foreach ($stats['queues'] as $queue => $queueStats) {
            if (!$queueStats['is_healthy']) {
                $alerts[] = [
                    'queue' => $queue,
                    'severity' => self::getAlertSeverity($queueStats),
                    'message' => self::generateAlertMessage($queueStats),
                    'metrics' => [
                        'total_jobs' => $queueStats['total_jobs'],
                        'failure_rate' => $queueStats['failure_rate'],
                        'avg_processing_time' => $queueStats['avg_processing_time'],
                        'oldest_job_age' => $queueStats['oldest_job_age'],
                    ],
                    'timestamp' => now()->toDateTimeString(),
                ];
            }
        }

        return $alerts;
    }

    /**
     * Get alert severity based on queue stats
     */
    private static function getAlertSeverity(array $stats): string
    {
        $thresholds = self::DEFAULT_THRESHOLDS;

        // Critical alerts
        if ($stats['total_jobs'] > $thresholds['max_queue_size'] * 0.9) {
            return 'critical';
        }

        if ($stats['failure_rate'] > $thresholds['max_failure_rate'] * 2) {
            return 'critical';
        }

        // High severity
        if ($stats['total_jobs'] > $thresholds['max_queue_size'] * 0.7) {
            return 'high';
        }

        if ($stats['failure_rate'] > $thresholds['max_failure_rate']) {
            return 'high';
        }

        // Medium severity
        if ($stats['avg_processing_time'] > $thresholds['max_processing_time']) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Generate alert message
     */
    private static function generateAlertMessage(array $stats): string
    {
        $issues = [];

        $thresholds = self::DEFAULT_THRESHOLDS;

        if ($stats['total_jobs'] > $thresholds['max_queue_size'] * 0.7) {
            $issues[] = "high queue size ({$stats['total_jobs']} jobs)";
        }

        if ($stats['failure_rate'] > $thresholds['max_failure_rate']) {
            $issues[] = "high failure rate ({$stats['failure_rate']}%)";
        }

        if ($stats['avg_processing_time'] > $thresholds['max_processing_time']) {
            $issues[] = "slow processing ({$stats['avg_processing_time']}s avg)";
        }

        if ($stats['oldest_job_age'] > 1800) {
            $issues[] = "old jobs waiting ({$stats['oldest_job_age']}s oldest)";
        }

        return "Queue '{$stats['queue_name']}' has " . implode(', ', $issues);
    }

    /**
     * Get queue monitoring dashboard data
     */
    public static function getDashboardData(): array
    {
        $stats = self::getQueueStats();
        $alerts = self::getHealthAlerts();

        // Calculate trends (placeholder - would need historical data)
        $trends = [
            'jobs_trend' => 'stable', // Would calculate from historical data
            'failure_trend' => 'decreasing',
            'processing_trend' => 'improving',
        ];

        return [
            'summary' => [
                'total_queues' => count($stats['queues']),
                'healthy_queues' => count(array_filter($stats['queues'], fn($q) => $q['is_healthy'])),
                'total_jobs' => $stats['total_jobs'],
                'total_failed' => $stats['total_failed'],
                'total_processing' => $stats['total_processing'],
                'alert_count' => count($alerts),
            ],
            'queues' => $stats['queues'],
            'alerts' => $alerts,
            'trends' => $trends,
            'last_updated' => now()->toDateTimeString(),
        ];
    }

    /**
     * Clear old failed jobs (maintenance task)
     */
    public static function cleanupOldFailedJobs(int $days = 7): int
    {
        $cutoff = now()->subDays($days);
        
        $deleted = DB::table('failed_jobs')
            ->where('failed_at', '<', $cutoff)
            ->delete();

        Log::info('Cleaned up old failed jobs', [
            'days_cutoff' => $days,
            'deleted_count' => $deleted,
            'cutoff_date' => $cutoff->toDateTimeString(),
        ]);

        return $deleted;
    }

    /**
     * Get queue performance metrics for monitoring
     */
    public static function getPerformanceMetrics(): array
    {
        $stats = self::getQueueStats();
        $metrics = [];

        foreach ($stats['queues'] as $queue => $queueStats) {
            $metrics[$queue] = [
                'throughput' => self::calculateThroughput($queue),
                'latency' => $queueStats['avg_processing_time'],
                'error_rate' => $queueStats['failure_rate'],
                'queue_depth' => $queueStats['total_jobs'],
                'utilization' => self::calculateUtilization($queue),
            ];
        }

        return $metrics;
    }

    /**
     * Calculate queue throughput (jobs per minute)
     */
    private static function calculateThroughput(string $queue): float
    {
        // This would need proper job completion tracking
        // For now, return an estimated value
        return rand(5, 20); // Placeholder
    }

    /**
     * Calculate queue utilization
     */
    private static function calculateUtilization(string $queue): float
    {
        $maxCapacity = self::DEFAULT_THRESHOLDS['max_queue_size'];
        $currentJobs = DB::table('jobs')->where('queue', $queue)->count();

        return min(1.0, $currentJobs / $maxCapacity);
    }

    /**
     * Check if queue monitoring is enabled
     */
    public static function isEnabled(): bool
    {
        return config('queue.monitoring.enabled', true);
    }

    /**
     * Get monitoring configuration
     */
    public static function getConfig(): array
    {
        return [
            'enabled' => self::isEnabled(),
            'thresholds' => self::DEFAULT_THRESHOLDS,
            'alert_channels' => config('queue.monitoring.alert_channels', ['log', 'email']),
            'cleanup_interval' => config('queue.monitoring.cleanup_interval', 'daily'),
        ];
    }
}
