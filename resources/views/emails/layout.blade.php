<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'EsperWorks')</title>
    <style>
        body { margin: 0; padding: 0; background: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1e293b; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 40px 20px; }
        .card { background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden; }
        .header { background: #29235c; padding: 24px 32px; text-align: center; }
        .header img { height: 36px; }
        .header h1 { color: #ffffff; font-size: 18px; margin: 8px 0 0; font-weight: 700; }
        .body { padding: 32px; }
        .body p { font-size: 14px; line-height: 1.7; color: #475569; margin: 0 0 16px; }
        .body h2 { font-size: 20px; color: #1e293b; margin: 0 0 8px; font-weight: 700; }
        .btn { display: inline-block; padding: 12px 28px; background: #00983a; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; }
        .btn-outline { display: inline-block; padding: 12px 28px; border: 2px solid #29235c; color: #29235c; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; }
        .info-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin: 20px 0; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #94a3b8; }
        .info-value { font-weight: 600; color: #1e293b; }
        .amount-box { background: #00983a; color: white; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0; }
        .amount-box .amount { font-size: 28px; font-weight: 800; }
        .amount-box .label { font-size: 12px; opacity: 0.85; margin-top: 4px; }
        .footer { text-align: center; padding: 24px 32px; border-top: 1px solid #f1f5f9; }
        .footer p { font-size: 11px; color: #94a3b8; margin: 0; }
        .footer a { color: #00983a; text-decoration: none; }
        .credential-box { background: #29235c; border-radius: 8px; padding: 16px; margin: 12px 0; }
        .credential-box p { color: #e2e8f0; font-size: 12px; margin: 0 0 4px; }
        .credential-box .value { color: #ffffff; font-size: 16px; font-weight: 700; font-family: monospace; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <div class="header">
                <img src="{{ config('app.url') }}/storage/logo.png" alt="EsperWorks" style="height: 36px; margin-bottom: 6px;" onerror="this.style.display='none'">
                <h1>EsperWorks</h1>
            </div>
            <div class="body">
                @yield('content')
            </div>
            <div class="footer">
                <p>Powered by <a href="https://esperworks.com">EsperWorks</a> &bull; Modern invoicing for African businesses</p>
                <p style="margin-top: 8px;">&copy; {{ date('Y') }} EsperWorks. All rights reserved.</p>
            </div>
        </div>
    </div>
</body>
</html>
