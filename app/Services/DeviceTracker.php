<?php

namespace App\Services;

use App\Models\LoginDevice;
use App\Models\User;
use Illuminate\Http\Request;

class DeviceTracker
{
    /**
     * Record or update a login device for the given user from the current request.
     */
    public static function track(User $user, Request $request): LoginDevice
    {
        $ua = $request->userAgent() ?? '';
        $ip = $request->ip();
        $parsed = self::parseUserAgent($ua);
        $geo = self::geolocate($ip);

        $device = LoginDevice::updateOrCreate(
            [
                'user_id' => $user->id,
                'ip_address' => $ip,
                'browser' => $parsed['browser'],
                'platform' => $parsed['platform'],
            ],
            [
                'user_agent' => substr($ua, 0, 1000),
                'device_type' => $parsed['device_type'],
                'browser_version' => $parsed['browser_version'],
                'platform_version' => $parsed['platform_version'],
                'device_name' => $parsed['device_name'],
                'country' => $geo['country'],
                'city' => $geo['city'],
                'region' => $geo['region'],
                'latitude' => $geo['latitude'],
                'longitude' => $geo['longitude'],
                'last_active_at' => now(),
            ]
        );

        return $device;
    }

    public static function parseUserAgent(string $ua): array
    {
        $result = [
            'device_type' => 'desktop',
            'browser' => 'Unknown',
            'browser_version' => null,
            'platform' => 'Unknown',
            'platform_version' => null,
            'device_name' => null,
        ];

        // Device type
        if (preg_match('/Mobile|Android.*Mobile|iPhone|iPod/i', $ua)) {
            $result['device_type'] = 'mobile';
        } elseif (preg_match('/iPad|Android(?!.*Mobile)|Tablet/i', $ua)) {
            $result['device_type'] = 'tablet';
        }

        // Platform / OS
        if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
            $result['platform'] = 'Windows';
            $result['platform_version'] = $m[1];
        } elseif (preg_match('/Mac OS X ([\d_.]+)/i', $ua, $m)) {
            $result['platform'] = 'macOS';
            $result['platform_version'] = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/iPhone OS ([\d_]+)/i', $ua, $m)) {
            $result['platform'] = 'iOS';
            $result['platform_version'] = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Android ([\d.]+)/i', $ua, $m)) {
            $result['platform'] = 'Android';
            $result['platform_version'] = $m[1];
        } elseif (preg_match('/Linux/i', $ua)) {
            $result['platform'] = 'Linux';
        } elseif (preg_match('/CrOS/i', $ua)) {
            $result['platform'] = 'Chrome OS';
        }

        // Browser
        if (preg_match('/Edg(?:e|A|iOS)?\/([\d.]+)/i', $ua, $m)) {
            $result['browser'] = 'Edge';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/OPR\/([\d.]+)/i', $ua, $m)) {
            $result['browser'] = 'Opera';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m) && !preg_match('/Edg|OPR/i', $ua)) {
            $result['browser'] = 'Chrome';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/Safari\/([\d.]+)/i', $ua, $m) && !preg_match('/Chrome|CriOS/i', $ua)) {
            $result['browser'] = 'Safari';
            if (preg_match('/Version\/([\d.]+)/i', $ua, $v)) {
                $result['browser_version'] = $v[1];
            }
        } elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) {
            $result['browser'] = 'Firefox';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/CriOS\/([\d.]+)/i', $ua, $m)) {
            $result['browser'] = 'Chrome';
            $result['browser_version'] = $m[1];
        }

        // Device name (phone models)
        if (preg_match('/iPhone/i', $ua)) {
            $result['device_name'] = 'iPhone';
        } elseif (preg_match('/iPad/i', $ua)) {
            $result['device_name'] = 'iPad';
        } elseif (preg_match('/Samsung|SM-[A-Z]\d+/i', $ua, $m)) {
            if (preg_match('/SM-([A-Z]\d+[A-Z]?)/i', $ua, $sm)) {
                $result['device_name'] = 'Samsung ' . $sm[1];
            } else {
                $result['device_name'] = 'Samsung';
            }
        } elseif (preg_match('/(TECNO|Infinix|itel|HUAWEI|Xiaomi|Redmi|OPPO|vivo|Pixel)\s?([\w-]*)/i', $ua, $m)) {
            $result['device_name'] = trim($m[1] . ' ' . ($m[2] ?? ''));
        }

        return $result;
    }

    /**
     * Best-effort IP geolocation using ip-api.com (free, no key needed, 45 req/min).
     * Falls back to empty values on failure.
     */
    public static function geolocate(?string $ip): array
    {
        $empty = ['country' => null, 'city' => null, 'region' => null, 'latitude' => null, 'longitude' => null];

        if (!$ip || in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
            return $empty;
        }

        try {
            $endpoint = rtrim(config('services.geoip.endpoint', env('GEOIP_ENDPOINT', 'http://ip-api.com/json')), '/');
            $url = $endpoint . '/' . urlencode($ip) . '?fields=status,country,regionName,city,lat,lon';
            $response = @file_get_contents($url, false, stream_context_create([
                'http' => ['timeout' => 2],
            ]));

            if (!$response) return $empty;

            $data = json_decode($response, true);
            if (($data['status'] ?? '') !== 'success') return $empty;

            return [
                'country' => $data['country'] ?? null,
                'city' => $data['city'] ?? null,
                'region' => $data['regionName'] ?? null,
                'latitude' => $data['lat'] ?? null,
                'longitude' => $data['lon'] ?? null,
            ];
        } catch (\Exception $e) {
            return $empty;
        }
    }
}
