<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EsperWorks - Two-Factor Backup Codes</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }
        .content {
            padding: 40px;
        }
        .codes-container {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 30px;
            margin: 20px 0;
        }
        .codes-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        .backup-code {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 15px;
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 2px;
            color: #2d3748;
            transition: all 0.2s ease;
        }
        .backup-code:hover {
            border-color: #667eea;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .warning h3 {
            margin: 0 0 10px 0;
            color: #856404;
        }
        .warning ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .warning li {
            margin: 5px 0;
        }
        .instructions {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .instructions h3 {
            margin: 0 0 10px 0;
            color: #0c5460;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px 40px;
            text-align: center;
            color: #6c757d;
            font-size: 14px;
            border-top: 1px solid #e9ecef;
        }
        .print-note {
            font-size: 12px;
            color: #6c757d;
            text-align: center;
            margin-top: 10px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Two-Factor Backup Codes</h1>
            <p>Save these codes for account recovery</p>
        </div>
        
        <div class="content">
            <p>Hi {{ $userName }},</p>
            <p>Thank you for enabling two-factor authentication on your EsperWorks account. Below are your 10 backup codes that you can use to access your account if you lose access to your primary authentication method.</p>
            
            <div class="warning">
                <h3>⚠️ Important Security Notice</h3>
                <ul>
                    <li>Each code can only be used once</li>
                    <li>Store these codes in a safe, secure location</li>
                    <li>Consider printing this email and storing it offline</li>
                    <li>Do not share these codes with anyone</li>
                    <li>Generate new codes if you suspect they've been compromised</li>
                </ul>
            </div>
            
            <div class="codes-container">
                <h3 style="margin-top: 0; text-align: center; color: #2d3748;">Your Backup Codes</h3>
                <div class="codes-grid">
                    @foreach($codes as $code)
                        <div class="backup-code">{{ $code }}</div>
                    @endforeach
                </div>
                <p class="print-note">💡 Tip: Print this page and store it in a secure location</p>
            </div>
            
            <div class="instructions">
                <h3>📋 How to Use Backup Codes</h3>
                <ol style="margin: 10px 0; padding-left: 20px;">
                    <li>When prompted for 2FA, select "Use backup code"</li>
                    <li>Enter any unused backup code from the list above</li>
                    <li>Once used, cross out that code as it cannot be reused</li>
                    <li>Generate new codes when you have fewer than 3 remaining</li>
                </ol>
            </div>
            
            <p>Keep this email safe and accessible. You'll need these codes if you can't receive 2FA codes via email.</p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} EsperWorks. All rights reserved.</p>
            <p>Need help? Contact us at support@esperworks.com</p>
        </div>
    </div>
</body>
</html>
