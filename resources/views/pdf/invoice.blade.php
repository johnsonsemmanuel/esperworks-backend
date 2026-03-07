<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
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

        .invoice-title {
            text-align: right;
        }

        .invoice-badge {
            display: inline-block;
            background: #29235c;
            color: white;
            font-size: 12px;
            font-weight: 800;
            padding: 6px 16px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .invoice-number {
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }

        .meta-section {
            margin-bottom: 30px;
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
        }

        .info-table .value {
            text-align: right;
            font-weight: 600;
            color: #1e293b;
        }

        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            margin-top: 40px;
        }

        table.items th {
            background: #f8fafc;
            color: #475569;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            text-align: left;
            font-weight: 700;
            border-bottom: 2px solid #e2e8f0;
        }

        table.items th:last-child {
            text-align: right;
        }

        table.items th:nth-child(2),
        table.items th:nth-child(3) {
            text-align: right;
        }

        table.items td {
            padding: 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 11px;
            color: #1e293b;
            vertical-align: top;
        }

        table.items td:last-child {
            text-align: right;
            font-weight: 600;
        }

        table.items td:nth-child(2),
        table.items td:nth-child(3) {
            text-align: right;
            color: #475569;
        }

        .notes-section {
            float: left;
            width: 55%;
            padding-right: 40px;
        }

        .totals-section {
            float: right;
            width: 35%;
        }

        .notes-box {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #f1f5f9;
        }

        .notes-text {
            font-size: 11px;
            color: #475569;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 11px;
            color: #475569;
        }

        .totals-row .label {
            font-weight: 500;
        }

        .totals-row .value {
            font-weight: 600;
            color: #1e293b;
        }

        .totals-row.final {
            border-top: 2px solid #e2e8f0;
            margin-top: 8px;
            padding-top: 12px;
            font-size: 14px;
            color: #1e293b;
        }

        .totals-row.final .value {
            font-weight: 800;
            color: #29235c;
        }

        .signatures {
            margin-top: 60px;
            page-break-inside: avoid;
        }

        .sig-box {
            float: left;
            width: 45%;
        }

        .sig-box:last-child {
            float: right;
        }

        .sig-line {
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 8px;
            min-height: 40px;
            position: relative;
        }

        .sig-image {
            max-height: 50px;
            display: block;
            margin-bottom: -5px;
        }

        .sig-text {
            font-size: 24px;
            color: #1e293b;
            line-height: 1;
            margin-bottom: 5px;
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

        /* Font classes for typed signatures */
        .font-serif {
            font-family: 'Times New Roman', serif;
        }

        .font-sans {
            font-family: 'Helvetica', Arial, sans-serif;
        }

        .font-mono {
            font-family: 'Courier New', monospace;
        }

        .italic {
            font-style: italic;
        }

        .font-bold {
            font-weight: bold;
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
                            @if($logoPath && file_exists($logoPath))
                                <img src="{{ $logoPath }}" alt="Logo"
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
                        <span class="invoice-badge">Invoice</span>
                        <div class="invoice-number">{{ $invoice->invoice_number }}</div>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Meta Info -->
        <div class="meta-section">
            <div class="meta-col-left">
                <div class="section-label">Bill To</div>
                <div class="client-name">{{ $client->name ?? 'Client' }}</div>
                <div class="client-info">{{ $client->email ?? '—' }}</div>
                @if(!empty($client?->phone))
                <div class="client-info">{{ $client->phone }}</div>@endif
                @if(!empty($client?->address))
                <div class="client-info">{{ $client->address }}</div>@endif
            </div>
            <div class="meta-col-right">
                <table class="info-table">
                    <tr>
                        <td class="label">Issue Date</td>
                        <td class="value">{{ $issueDate?->format('M d, Y') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Due Date</td>
                        <td class="value">{{ $dueDate?->format('M d, Y') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="label">Status</td>
                        <td class="value"
                            style="color: {{ $invoice->status === 'paid' ? '#16a34a' : ($invoice->status === 'overdue' ? '#dc2626' : '#29235c') }}">
                            {{ strtoupper($invoice->status) }}
                        </td>
                    </tr>
                </table>
            </div>
            <div style="clear: both;"></div>
        </div>

        <!-- Items -->
        <table class="items">
            <thead>
                <tr>
                    <th style="width: 45%">Description</th>
                    <th style="width: 15%">Qty</th>
                    <th style="width: 20%">Rate</th>
                    <th style="width: 20%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td>{{ $item->quantity }}</td>
                        <td>{{ number_format($item->rate, 2) }}</td>
                        <td>{{ number_format($item->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals & Notes -->
        <div>
            <div class="notes-section">
                @if($invoice->notes)
                    <div class="section-label">Notes</div>
                    <div class="notes-box">
                        <div class="notes-text">{{ $invoice->notes }}</div>
                    </div>
                @endif

                @if($invoice->payment_terms)
                    <div class="section-label" style="margin-top: 20px;">Payment Terms</div>
                    <div class="notes-text" style="color: #64748b;">{{ $invoice->payment_terms }}</div>
                @endif
            </div>

            <div class="totals-section">
                <table class="info-table">
                    <tr>
                        <td class="label">Subtotal</td>
                        <td class="value">{{ number_format($invoice->subtotal, 2) }}</td>
                    </tr>
                    @if($invoice->vat_rate > 0)
                        <tr>
                            <td class="label">VAT ({{ $invoice->vat_rate }}%)</td>
                            <td class="value">{{ number_format($invoice->vat_amount, 2) }}</td>
                        </tr>
                    @endif
                    <tr style="border-top: 2px solid #e2e8f0;">
                        <td class="label" style="padding-top: 12px; font-weight: 700; color: #1e293b; font-size: 13px;">
                            Total</td>
                        <td class="value" style="padding-top: 12px; font-weight: 800; font-size: 16px; color: #29235c;">
                            {{ $invoice->currency }} {{ number_format($invoice->total, 2) }}</td>
                    </tr>
                    @if($invoice->amount_paid > 0)
                        <tr>
                            <td class="label" style="color: #16a34a;">Paid to Date</td>
                            <td class="value" style="color: #16a34a;">- {{ number_format($invoice->amount_paid, 2) }}</td>
                        </tr>
                        <tr>
                            <td class="label" style="font-weight: 700; color: #dc2626;">Balance Due</td>
                            <td class="value" style="font-weight: 800; color: #dc2626;">
                                {{ number_format($invoice->amountDue(), 2) }}</td>
                        </tr>
                    @endif
                </table>
            </div>
            <div style="clear: both;"></div>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="sig-box">
                <div class="section-label">Business Signature</div>
                <div class="sig-line">
                    @if($businessSignature)
                        @if($businessSignature['type'] === 'typed')
                            <div class="{{ $businessSignature['cssClass'] }}">{{ $businessSignature['name'] }}</div>
                        @elseif($businessSignature['type'] === 'base64')
                            <img src="{{ $businessSignature['image'] }}" class="sig-image" alt="Signature">
                        @elseif($businessSignature['type'] === 'file')
                            <img src="{{ $businessSignature['path'] }}" class="sig-image" alt="Signature">
                        @endif
                    @endif
                </div>
                <div class="client-info">
                    <span
                        style="font-weight: 600; color: #1e293b;">{{ $invoice->business_signature_name ?? 'Pending' }}</span>
                    @if($invoice->business_signed_at)
                        <br>Date: {{ $invoice->business_signed_at->format('M d, Y') }}
                    @endif
                </div>
            </div>

            <div class="sig-box">
                <div class="section-label">Client Signature</div>
                <div class="sig-line">
                    @if($clientSignature)
                        @if($clientSignature['type'] === 'typed')
                            <div class="{{ $clientSignature['cssClass'] }}">{{ $clientSignature['name'] }}</div>
                        @elseif($clientSignature['type'] === 'base64')
                            <img src="{{ $clientSignature['image'] }}" class="sig-image" alt="Signature">
                        @elseif($clientSignature['type'] === 'file')
                            <img src="{{ $clientSignature['path'] }}" class="sig-image" alt="Signature">
                        @endif
                    @endif
                </div>
                <div class="client-info">
                    <span
                        style="font-weight: 600; color: #1e293b;">{{ $invoice->client_signature_name ?? ($invoice->client_signature_required ? 'Authorized Signature' : 'Not Required') }}</span>
                    @if($invoice->client_signed_at)
                        <br>Date: {{ $invoice->client_signed_at->format('M d, Y') }}
                    @endif
                </div>
            </div>
            <div style="clear: both;"></div>
        </div>

        <div class="footer">
            Page 1 of 1 &bull; Powered by EsperWorks &bull; esperworks.com
            @if($business->email)
            <br>For payment or support, contact {{ $business->email }}
            @endif
        </div>
    </div>
</body>

</html>