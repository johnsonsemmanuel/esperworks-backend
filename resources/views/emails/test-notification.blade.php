<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Test Notification - EsperWorks</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #29235c; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px 20px; background: #f9f9f9; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>EsperWorks</h1>
            <p>Test Notification</p>
        </div>
        <div class="content">
            <h2>Test Email Successful!</h2>
            <p>This is a test email to confirm that your notification system is working correctly.</p>
            <p>You can now receive email notifications for your business activities.</p>
            <br>
            <p>Best regards,<br>The EsperWorks Team</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} EsperWorks. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
