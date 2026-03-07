<?php

return [
    // Base URL for IP geolocation lookups used by DeviceTracker.
    // Override via GEOIP_ENDPOINT env or services.geoip.endpoint config.
    'endpoint' => env('GEOIP_ENDPOINT', 'http://ip-api.com/json'),
];

