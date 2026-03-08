<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;

class SignatureAuditLog extends Model
{
    protected $fillable = [
        'signable_type', 'signable_id',
        'event', 'signer_type', 'signer_name', 'signer_email',
        'ip_address', 'user_agent', 'device_type', 'browser', 'os',
        'latitude', 'longitude', 'timezone',
        'signature_method', 'document_hash', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Log a signature audit event from a request context.
     */
    public static function record(
        Model $signable,
        string $event,
        string $signerType,
        string $signerName,
        string $documentHash,
        ?Request $request = null,
        array $extra = [],
    ): self {
        $request = $request ?? request();
        $ua = $request->userAgent() ?? '';

        return self::create([
            'signable_type' => get_class($signable),
            'signable_id' => $signable->getKey(),
            'event' => $event,
            'signer_type' => $signerType,
            'signer_name' => $signerName,
            'signer_email' => $extra['email'] ?? null,
            'ip_address' => $request->ip() ?? '0.0.0.0',
            'user_agent' => $ua,
            'device_type' => self::parseDeviceType($ua),
            'browser' => self::parseBrowser($ua),
            'os' => self::parseOs($ua),
            'latitude' => $extra['latitude'] ?? null,
            'longitude' => $extra['longitude'] ?? null,
            'timezone' => $extra['timezone'] ?? null,
            'signature_method' => $extra['signature_method'] ?? null,
            'document_hash' => $documentHash,
            'metadata' => $extra['metadata'] ?? null,
        ]);
    }

    private static function parseDeviceType(string $ua): string
    {
        $ua = strtolower($ua);
        if (preg_match('/mobile|android|iphone|ipod/', $ua)) return 'mobile';
        if (preg_match('/tablet|ipad/', $ua)) return 'tablet';
        return 'desktop';
    }

    private static function parseBrowser(string $ua): string
    {
        if (str_contains($ua, 'Firefox')) return 'Firefox';
        if (str_contains($ua, 'Edg')) return 'Edge';
        if (str_contains($ua, 'OPR') || str_contains($ua, 'Opera')) return 'Opera';
        if (str_contains($ua, 'Chrome')) return 'Chrome';
        if (str_contains($ua, 'Safari')) return 'Safari';
        return 'Other';
    }

    private static function parseOs(string $ua): string
    {
        if (str_contains($ua, 'Windows')) return 'Windows';
        if (str_contains($ua, 'Mac OS')) return 'macOS';
        if (str_contains($ua, 'Linux')) return 'Linux';
        if (str_contains($ua, 'Android')) return 'Android';
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) return 'iOS';
        return 'Other';
    }
}
