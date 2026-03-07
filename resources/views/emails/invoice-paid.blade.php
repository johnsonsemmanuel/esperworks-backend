<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice Paid - {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #00983a; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px 20px; background: #f9f9f9; }
        .invoice-details { background: white; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{ $business->name }}</h1>
            <p>Payment Received!</p>
        </div>
        <div class="content">
            <h2>🎉 Invoice Has Been Paid</h2>
            <p>Great news! Your client has completed payment for the invoice.</p>
            
            <div class="invoice-details">
                <h3>{{ $invoice->invoice_number }}</h3>
                <p><strong>Client:</strong> {{ $client->name }}</p>
                <p><strong>Amount Paid:</strong> GH₵{{ number_format($invoice->amount_paid, 2) }}</p>
                <p><strong>Total Amount:</strong> GH₵{{ number_format($invoice->total, 2) }}</p>
                <p><strong>Payment Date:</strong> {{ $invoice->paid_at->format('M d, Y') }}</p>
                <p><strong>Status:</strong> {{ ucfirst($invoice->status) }}</p>
            </div>
            
            <p>Thank you for your business! The payment has been processed successfully.</p>
            <br>
            <p>Best regards,<br>The {{ $business->name }} Team</p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} {{ $business->name }}. Powered by EsperWorks.</p>
        </div>
    </div>
</body>
</html>
