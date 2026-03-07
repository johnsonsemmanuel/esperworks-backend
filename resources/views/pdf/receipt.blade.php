<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Receipt - {{ $payment->reference }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica', Arial, sans-serif;
            font-size: 12px;
            color: #1e293b;
            line-height: 1.5;
        }

        .page {
            padding: 40px;
        }

        .header {
            margin-bottom: 40px;
        }

        .brand h1 {
            font-size: 24px;
            color: #29235c;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .brand p {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 2px;
        }

        .receipt-badge {
            display: inline-block;
            background: #059669;
            color: white;
            font-size: 12px;
            font-weight: 800;
            padding: 6px 16px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .receipt-number {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }

        .meta-section {
            margin-bottom: 40px;
        }

        .meta-col-left {
            float: left;
            width: 50%;
        }

        .meta-col-right {
            float: right;
            width: 40%;
        }

        .section-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #94a3b8;
            letter-spacing: 0.5px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .client-name {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
        }

        .client-info {
            font-size: 11px;
            color: #475569;
            margin-bottom: 2px;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
        }

        .info-table td {
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .info-table .label {
            color: #64748b;
            font-weight: 500;
            width: 140px;
        }

        .info-table .value {
            text-align: right;
            font-weight: 600;
            color: #1e293b;
        }

        .amount-box {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            margin: 40px 0;
        }

        .amount-label {
            font-size: 11px;
            text-transform: uppercase;
            color: #15803d;
            letter-spacing: 1px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .amount-value {
            font-size: 36px;
            font-weight: 800;
            color: #16a34a;
            margin-bottom: 8px;
        }

        .amount-status {
            display: inline-block;
            background: #16a34a;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .invoice-details {
            background: #f8fafc;
            border-radius: 8px;
            padding: 24px;
            border: 1px solid #f1f5f9;
            margin-top: 40px;
        }

        .invoice-title {
            font-size: 12px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 16px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 8px;
        }

        .footer {
            position: fixed;
            bottom: 40px;
            left: 40px;
            right: 40px;
            text-align: center;
            border-top: 1px solid #f1f5f9;
            padding-top: 20px;
            font-size: 9px;
            color: #94a3b8;
        }
    </style>
</head>

<body>
    <div class="page">
        <!-- Header -->
        <div class="header">
            <table style="width: 100%">
                <tr>
                    <td style="vertical-align: top;">
                        <div class="brand">
                            @if($business->logo)
                                <img src="{{ storage_path('app/public/' . $business->logo) }}" alt="Logo"
                                    style="height: 40px; margin-bottom: 15px; object-fit: contain;">
                            @endif
                            <h1>{{ $business->name }}</h1>
                            <p>{{ $business->address }}</p>
                            <p>{{ $business->email }} &bull; {{ $business->phone }}</p>
                            @if($business->tin)
                            <p>TIN: {{ $business->tin }}</p>@endif
                        </div>
                    </td>
                    <td style="vertical-align: top; text-align: right;">
                        <span class="receipt-badge">Receipt</span>
                        <div class="receipt-number">{{ $payment->reference }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Meta Info -->
        <div class="meta-section">
            <div class="meta-col-left">
                <div class="section-label">Received From</div>
                <div class="client-name">{{ $client->name }}</div>
                <div class="client-info">{{ $client->email }}</div>
                @if($client->phone)
                <div class="client-info">{{ $client->phone }}</div>@endif
            </div>
            <div class="meta-col-right">
                <table class="info-table">
                    <tr>
                        <td class="label">Payment Date</td>
                        <td class="value">
                            {{ $payment->paid_at ? \Carbon\Carbon::parse($payment->paid_at)->format('M d, Y') : now()->format('M d, Y') }}
                        </td>
                    </tr>
                    <tr>
                        <td class="label">Payment Method</td>
                        <td class="value">{{ ucfirst($payment->method ?? 'Online') }}</td>
                    </tr>
                    <tr>
                        <td class="label">Currency</td>
                        <td class="value">{{ $payment->currency }}</td>
                    </tr>
                </table>
            </div>
            <div style="clear: both;"></div>
        </div>

        <!-- Amount Box -->
        <div class="amount-box">
            <div class="amount-label">Amount Paid</div>
            <div class="amount-value">GH₵ {{ number_format($payment->amount, 2) }}</div>
            <div class="amount-status">Payment Successful</div>
        </div>

        <!-- Invoice Details -->
        @if($invoice)
            <div class="invoice-details">
                <div class="invoice-title">Applied to Invoice #{{ $invoice->invoice_number }}</div>
                <table class="info-table">
                    <tr>
                        <td class="label">Invoice Total</td>
                        <td class="value">GH₵ {{ number_format($invoice->total, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Total Paid</td>
                        <td class="value" style="color: #16a34a;">GH₵ {{ number_format($invoice->amount_paid, 2) }}</td>
                    </tr>
                    @if($invoice->amountDue() > 0)
                        <tr>
                            <td class="label">Balance Due</td>
                            <td class="value" style="color: #dc2626;">GH₵ {{ number_format($invoice->amountDue(), 2) }}</td>
                        </tr>
                    @else
                        <tr>
                            <td class="label">Balance Due</td>
                            <td class="value" style="color: #16a34a;">GH₵ 0.00 (Fully Paid)</td>
                        </tr>
                    @endif
                </table>
            </div>
        @endif

        <div class="footer">
            <p>This is a computer-generated receipt and does not require a signature.</p>
            <p style="margin-top: 4px;">Powered by EsperWorks &bull; esperworks.com</p>
        </div>
    </div>
</body>

</html>