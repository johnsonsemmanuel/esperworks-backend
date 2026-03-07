# Email Configuration Setup

## Current Issue
Email credentials are using placeholder values:
- MAIL_HOST=mail.yourdomain.com (invalid)
- MAIL_USERNAME=noreply@yourdomain.com (invalid)
- MAIL_PASSWORD=your_email_password (invalid)

## Solution Options

### Option 1: Use Gmail SMTP (Free for testing)
```bash
# Add to your .env file
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-gmail@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-gmail@gmail.com
MAIL_FROM_NAME="EsperWorks"
```

### Option 2: Use Mailtrap (Development testing)
```bash
# Add to your .env file
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your-mailtrap-username
MAIL_PASSWORD=your-mailtrap-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@esperworks.test
MAIL_FROM_NAME="EsperWorks"
```

### Option 3: Use cPanel Email (Production)
```bash
# Add to your .env file
MAIL_MAILER=smtp
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=465
MAIL_USERNAME=noreply@yourdomain.com
MAIL_PASSWORD=your-actual-email-password
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="EsperWorks"
```

## Gmail Setup Instructions
1. Enable 2-factor authentication on your Gmail account
2. Go to Google Account settings > Security
3. Enable "App passwords"
4. Generate a new app password
5. Use the app password (not your regular password) in MAIL_PASSWORD

## Testing Email Configuration
```bash
# Clear cache after updating .env
php artisan config:cache
php artisan cache:clear

# Test email sending
php artisan tinker
> Mail::raw('Test email', function($message) {
    $message->to('your-test-email@example.com')->subject('Test');
  });
```

## Production Considerations
- Use a dedicated email service (SendGrid, Mailgun, AWS SES)
- Set up proper SPF/DKIM records
- Monitor email deliverability
- Use transactional email templates
