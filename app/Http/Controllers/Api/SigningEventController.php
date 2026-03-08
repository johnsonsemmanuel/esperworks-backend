<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\SignatureAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SigningEventController extends Controller
{
    /**
     * SSE endpoint: stream real-time signing status for a contract.
     * Business owner connects to this to watch client signing activity.
     *
     * GET /api/businesses/{business}/contracts/{contract}/signing-events
     */
    public function contractEvents(Request $request, $business, $contract): StreamedResponse
    {
        $contractObj = $contract instanceof Contract ? $contract : Contract::findOrFail($contract);

        return new StreamedResponse(function () use ($contractObj) {
            $lastEventId = 0;
            $timeout = 30; // seconds before closing (client auto-reconnects)
            $start = time();

            while ((time() - $start) < $timeout) {
                // Check for new signing events in cache
                $cacheKey = "signing_events:contract:{$contractObj->id}";
                $events = Cache::get($cacheKey, []);

                foreach ($events as $idx => $event) {
                    if ($idx > $lastEventId) {
                        echo "id: {$idx}\n";
                        echo "event: {$event['type']}\n";
                        echo "data: " . json_encode($event['data']) . "\n\n";
                        $lastEventId = $idx;
                    }
                }

                // Also check contract status directly for signing completion
                $contractObj->refresh();
                if ($contractObj->client_signed_at && $lastEventId === 0) {
                    echo "event: document.signed\n";
                    echo "data: " . json_encode([
                        'signer' => $contractObj->client_signature_name,
                        'signed_at' => $contractObj->client_signed_at->toIso8601String(),
                        'status' => $contractObj->status,
                    ]) . "\n\n";
                    break;
                }

                if (ob_get_level() > 0) ob_flush();
                flush();

                if (connection_aborted()) break;

                sleep(2);
            }

            // Send keepalive/close
            echo "event: timeout\n";
            echo "data: {\"message\":\"reconnect\"}\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Record a signing activity event (called from public signing page).
     * Used to push real-time events to the business owner via SSE.
     *
     * POST /api/contracts/{token}/signing-activity
     */
    public function recordActivity(Request $request, string $token)
    {
        $request->validate([
            'event' => 'required|string|in:opened,reading,scrolled,signing,signed',
            'progress' => 'nullable|integer|min:0|max:100',
        ]);

        // Find contract by token
        $contract = Contract::where('signing_token', $token)
            ->where('token_expires_at', '>', now())
            ->first();

        if (!$contract) {
            return response()->json(['message' => 'Invalid or expired token'], 404);
        }

        // Push event to cache for SSE consumption
        $cacheKey = "signing_events:contract:{$contract->id}";
        $events = Cache::get($cacheKey, []);
        $eventIdx = count($events) + 1;

        $events[$eventIdx] = [
            'type' => 'document.' . $request->event,
            'data' => [
                'event' => $request->event,
                'progress' => $request->progress,
                'timestamp' => now()->toIso8601String(),
                'ip' => $request->ip(),
            ],
        ];

        // Keep only last 50 events, expire after 1 hour
        $events = array_slice($events, -50, null, true);
        Cache::put($cacheKey, $events, now()->addHour());

        // Update viewed_at on first open
        if ($request->event === 'opened' && !$contract->viewed_at) {
            $contract->update(['viewed_at' => now(), 'status' => 'viewed']);

            // Log to audit trail
            SignatureAuditLog::record(
                $contract,
                'opened',
                'client',
                $contract->client?->name ?? 'Client',
                $contract->generateContentHash(),
                $request,
                ['email' => $contract->client?->email]
            );
        }

        return response()->json(['message' => 'ok']);
    }
}
