<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Contract;
use App\Services\SecurityLogger;

/**
 * Middleware to validate public signing tokens for invoices and contracts.
 * Ensures tokens haven't expired and logs suspicious access attempts.
 */
class ValidatePublicToken
{
    public function handle(Request $request, Closure $next, string $modelType = 'invoice')
    {
        $token = $request->route('token');
        
        if (!$token) {
            SecurityLogger::logSecurityEvent(
                'public_token.missing',
                "Public token access attempted without token",
                null,
                $request
            );
            return response()->json([
                'message' => 'Invalid or missing access token'
            ], 403);
        }

        // Determine model based on route
        $model = null;
        if ($modelType === 'invoice' || str_contains($request->path(), 'invoice')) {
            $model = Invoice::where('signing_token', $token)->first();
        } elseif ($modelType === 'contract' || str_contains($request->path(), 'contract')) {
            $model = Contract::where('signing_token', $token)->first();
        }

        if (!$model) {
            SecurityLogger::logSecurityEvent(
                'public_token.invalid',
                "Invalid public token attempted: " . substr($token, 0, 10) . "...",
                null,
                $request
            );
            return response()->json([
                'message' => 'Document not found or link has expired'
            ], 404);
        }

        // Check token expiry
        if ($model->token_expires_at && now()->isAfter($model->token_expires_at)) {
            SecurityLogger::logSecurityEvent(
                'public_token.expired',
                "Expired token access attempted for {$modelType} #{$model->id}",
                null,
                $request
            );
            return response()->json([
                'message' => 'This link has expired. Please contact the business for a new link.',
                'expired_at' => $model->token_expires_at->toIso8601String()
            ], 410);
        }

        // Attach model to request for controller use
        $request->attributes->set('validated_model', $model);

        return $next($request);
    }
}
