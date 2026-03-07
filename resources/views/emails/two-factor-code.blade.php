<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EsperWorks - Two-Factor Authentication Code</title>
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
        .code-container {
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin: 20px 0;
        }
        .code {
            font-size: 36px;
            font-weight: 700;
            letter-spacing: 8px;
            color: #2d3748;
            font-family: 'Courier New', monospace;
            background: white;
            padding: 20px;
            border-radius: 6px;
            border: 2px solid #e9ecef;
            display: inline-block;
            min-width: 200px;
        }
        .expiry {
            color: #6c757d;
            font-size: 14px;
            margin-top: 10px;
        }
        .footer {
            background: #f8f9fa;
            padding: 20px 40px;
            text-align: center;
            color: #6c757d;
            font-size: 14px;
            border-top: 1px solid #e9ecef;
        }
        .security-tip {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: 6px;
            margin: 20px 0;
            font-size: 14px;
        }
        .security-tip strong {
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 Two-Factor Authentication</h1>
            <p>Enter this code to secure your account</p>
        </div>
        
        <div class="content">
            <p>Hi {{ $userName }},</p>
            <p>We've sent you a verification code to enhance the security of your EsperWorks account. Please enter the 6-digit code below:</p>
            
            <div class="code-container">
                <div class="code">{{ $code }}</div>
                <div class="expiry">
                    ⏰ This code expires in {{ $expiryMinutes }} minutes
                </div>
            </div>
            
            <div class="security-tip">
                <strong>🔒 Security Tip:</strong> Never share this code with anyone. Our team will never ask for it.
            </div>
            
            <p>If you didn't request this code, please secure your account immediately by changing your password.</p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} EsperWorks. All rights reserved.</p>
            <p>Need help? Contact us at support@esperworks.com</p>
        </div>
    </div>
</body>
</html>
