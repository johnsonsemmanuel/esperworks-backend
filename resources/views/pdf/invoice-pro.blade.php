@php
    $branding = $business->branding ?? [];
    $accent = $branding['invoice_accent'] ?? '#1e293b';
    $accentLight = $branding['invoice_header_bg'] ?? '#f1f5f9';
    $fontFamily = "'Inter', 'Helvetica Neue', Arial, sans-serif";
    $currency = $invoice->currency ?? 'GHS';
    $currencySymbol = match($currency) {
        'GHS' => 'GH₵',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        default => $currency . ' ',
    };

    $issueDate = $invoice->issue_date
        ? \Illuminate\Support\Carbon::parse($invoice->issue_date)
        : ($invoice->date ? \Illuminate\Support\Carbon::parse($invoice->date) : null);
    $dueDate = $invoice->due_date ? \Illuminate\Support\Carbon::parse($invoice->due_date) : null;
    $logoPath = !empty($business->logo) ? storage_path('app/public/' . $business->logo) : null;
    $now = now();

    $contentHash = hash('sha256', json_encode([
        'invoice_id' => $invoice->id,
        'invoice_number' => $invoice->invoice_number,
        'total' => $invoice->total,
        'created' => $invoice->created_at?->toIso8601String(),
    ]));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page { size: A4; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: {!! $fontFamily !!};
            font-size: 11px;
            color: #1e293b;
            line-height: 1.6;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .page {
            padding: 48px 52px;
            min-height: 297mm;
            position: relative;
        }

        /* ── HEADER ──────────────────────────── */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
        }
        .brand { max-width: 55%; }
        .brand-logo {
            margin-bottom: 14px;
        }
        .brand-logo img {
            max-height: 48px;
            max-width: 180px;
            object-fit: contain;
        }
        .brand-name {
            font-size: 22px;
            font-weight: 800;
            color: {{ $accent }};
            letter-spacing: -0.3px;
            margin-bottom: 6px;
        }
        .brand-detail {
            font-size: 10px;
            color: #64748b;
            line-height: 1.5;
        }
        .invoice-badge-area {
            text-align: right;
        }
        .invoice-badge {
            display: inline-block;
            background: {{ $accent }};
            color: white;
            font-size: 11px;
            font-weight: 800;
            padding: 6px 20px;
            border-radius: 100px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        .invoice-number {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .invoice-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 100px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .status-draft { background: #f1f5f9; color: #475569; }
        .status-sent { background: #dbeafe; color: #1d4ed8; }
        .status-viewed { background: #fef3c7; color: #b45309; }
        .status-paid { background: #dcfce7; color: #15803d; }
        .status-overdue { background: #fee2e2; color: #dc2626; }
        .status-partial { background: #fff7ed; color: #c2410c; }
        .status-partially_paid { background: #fff7ed; color: #c2410c; }
        .status-cancelled { background: #f1f5f9; color: #64748b; }

        /* ── META SECTION ────────────────────── */
        .meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 36px;
            gap: 40px;
        }
        .meta-block { flex: 1; }
        .meta-label {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        .client-name {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .client-detail {
            font-size: 10px;
            color: #64748b;
            line-height: 1.5;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .info-item {}
        .info-item-label {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 2px;
        }
        .info-item-value {
            font-size: 12px;
            font-weight: 600;
            color: #0f172a;
        }

        /* ── LINE ITEMS TABLE ────────────────── */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 28px;
        }
        .items-table thead th {
            background: {{ $accentLight }};
            color: #475569;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 12px 16px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
        }
        .items-table thead th:nth-child(2),
        .items-table thead th:nth-child(3),
        .items-table thead th:last-child {
            text-align: right;
        }
        .items-table tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 11px;
            color: #334155;
            vertical-align: top;
        }
        .items-table tbody td:nth-child(2),
        .items-table tbody td:nth-child(3) {
            text-align: right;
            color: #64748b;
        }
        .items-table tbody td:last-child {
            text-align: right;
            font-weight: 600;
            color: #0f172a;
        }
        .items-table tbody tr:last-child td {
            border-bottom: 2px solid #e2e8f0;
        }

        /* ── TOTALS + NOTES ──────────────────── */
        .bottom-section {
            display: flex;
            justify-content: space-between;
            gap: 40px;
        }
        .notes-area {
            flex: 1;
            max-width: 55%;
        }
        .notes-box {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px 20px;
            border: 1px solid #f1f5f9;
        }
        .notes-text {
            font-size: 10px;
            color: #64748b;
            line-height: 1.6;
            white-space: pre-wrap;
        }
        .totals-area {
            width: 240px;
            flex-shrink: 0;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 11px;
        }
        .totals-row .label { color: #64748b; font-weight: 500; }
        .totals-row .value { font-weight: 600; color: #0f172a; }
        .totals-divider {
            border-top: 2px solid #e2e8f0;
            margin: 8px 0;
        }
        .totals-total {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            font-size: 16px;
        }
        .totals-total .label { font-weight: 700; color: #0f172a; }
        .totals-total .value { font-weight: 800; color: {{ $accent }}; }
        .totals-paid {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 11px;
        }
        .totals-paid .label { color: #16a34a; font-weight: 500; }
        .totals-paid .value { color: #16a34a; font-weight: 600; }
        .totals-due {
            display: flex;
            justify-content: space-between;
            padding: 10px 14px;
            font-size: 14px;
            background: #fef2f2;
            border-radius: 6px;
            margin-top: 6px;
        }
        .totals-due .label { color: #dc2626; font-weight: 700; }
        .totals-due .value { color: #dc2626; font-weight: 800; }

        /* ── SIGNATURES ──────────────────────── */
        .signatures {
            margin-top: 52px;
            display: flex;
            justify-content: space-between;
            gap: 40px;
            page-break-inside: avoid;
        }
        .sig-box { flex: 1; }
        .sig-label {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 12px;
        }
        .sig-line {
            border-bottom: 2px solid #0f172a;
            min-height: 48px;
            display: flex;
            align-items: flex-end;
            justify-content: flex-start;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }
        .sig-image { max-height: 44px; }
        .sig-typed {
            font-size: 22px;
            font-style: italic;
            color: #0f172a;
            font-family: 'Times New Roman', serif;
        }
        .sig-info {
            font-size: 10px;
            color: #475569;
        }
        .sig-info strong { color: #0f172a; }
        .sig-pending {
            font-size: 10px;
            color: #94a3b8;
            font-style: italic;
            padding-top: 4px;
        }

        /* ── FOOTER ──────────────────────────── */
        .footer {
            position: fixed;
            bottom: 36px;
            left: 52px;
            right: 52px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 16px;
            border-top: 1px solid #f1f5f9;
            font-size: 9px;
            color: #94a3b8;
        }

        .watermark {
            position: fixed;
            bottom: 10px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            color: #d1d5db;
        }

        /* Font classes for typed signatures */
        .font-serif { font-family: 'Times New Roman', serif; }
        .font-sans { font-family: 'Helvetica', Arial, sans-serif; }
        .font-mono { font-family: 'Courier New', monospace; }
        .italic { font-style: italic; }
        .font-bold { font-weight: bold; }
    </style>
</head>
<body>
    @if(($business->plan ?? 'free') === 'free')
        <div class="watermark">Powered by EsperWorks &mdash; esperworks.com</div>
    @endif

    <div class="page">
        {{-- ── HEADER ──────────────────────────────── --}}
        <div class="header">
            <div class="brand">
                @if($logoPath && file_exists($logoPath))
                    <div class="brand-logo">
                        <img src="{{ $logoPath }}" alt="{{ $business->name }}">
                    </div>
                @endif
                <div class="brand-name">{{ $business->name }}</div>
                <div class="brand-detail">
                    {{ $business->address }}<br>
                    {{ $business->email }}@if($business->phone) &bull; {{ $business->phone }}@endif
                    @if($business->tin)<br>TIN: {{ $business->tin }}@endif
                    @if($business->website)<br>{{ $business->website }}@endif
                </div>
            </div>
            <div class="invoice-badge-area">
                <div class="invoice-badge">Invoice</div>
                <div class="invoice-number">{{ $invoice->invoice_number }}</div>
                <div>
                    <span class="invoice-status status-{{ $invoice->status }}">{{ strtoupper($invoice->status) }}</span>
                </div>
            </div>
        </div>

        {{-- ── META INFO ───────────────────────────── --}}
        <div class="meta">
            <div class="meta-block">
                <div class="meta-label">Bill To</div>
                <div class="client-name">{{ $client->name ?? 'Client' }}</div>
                <div class="client-detail">
                    {{ $client->email ?? '' }}
                    @if(!empty($client->phone))<br>{{ $client->phone }}@endif
                    @if(!empty($client->address))<br>{{ $client->address }}@endif
                </div>
            </div>
            <div class="meta-block">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-item-label">Issue Date</div>
                        <div class="info-item-value">{{ $issueDate?->format('M d, Y') ?? '—' }}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-label">Due Date</div>
                        <div class="info-item-value" style="{{ $dueDate && $dueDate->isPast() && $invoice->status !== 'paid' ? 'color: #dc2626;' : '' }}">
                            {{ $dueDate?->format('M d, Y') ?? '—' }}
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-label">Currency</div>
                        <div class="info-item-value">{{ $currency }}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-item-label">Payment Method</div>
                        <div class="info-item-value">{{ ucfirst($invoice->payment_method ?? 'All') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── LINE ITEMS ──────────────────────────── --}}
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 48%">Description</th>
                    <th style="width: 14%">Qty</th>
                    <th style="width: 18%">Rate</th>
                    <th style="width: 20%">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ $currencySymbol }} {{ number_format($item->rate, 2) }}</td>
                    <td>{{ $currencySymbol }} {{ number_format($item->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        {{-- ── TOTALS + NOTES ──────────────────────── --}}
        <div class="bottom-section">
            <div class="notes-area">
                @if($invoice->notes)
                    <div class="meta-label">Notes</div>
                    <div class="notes-box">
                        <div class="notes-text">{{ $invoice->notes }}</div>
                    </div>
                @endif
                @if($invoice->payment_terms)
                    <div class="meta-label" style="margin-top: 16px;">Payment Terms</div>
                    <div class="notes-text" style="font-size: 10px; color: #64748b;">{{ $invoice->payment_terms }}</div>
                @endif
            </div>
            <div class="totals-area">
                <div class="totals-row">
                    <span class="label">Subtotal</span>
                    <span class="value">{{ $currencySymbol }} {{ number_format($invoice->subtotal, 2) }}</span>
                </div>
                @if($invoice->vat_rate > 0)
                <div class="totals-row">
                    <span class="label">VAT ({{ $invoice->vat_rate }}%)</span>
                    <span class="value">{{ $currencySymbol }} {{ number_format($invoice->vat_amount, 2) }}</span>
                </div>
                @endif
                <div class="totals-divider"></div>
                <div class="totals-total">
                    <span class="label">Total</span>
                    <span class="value">{{ $currencySymbol }} {{ number_format($invoice->total, 2) }}</span>
                </div>
                @if($invoice->amount_paid > 0)
                    <div class="totals-paid">
                        <span class="label">Paid to Date</span>
                        <span class="value">- {{ $currencySymbol }} {{ number_format($invoice->amount_paid, 2) }}</span>
                    </div>
                    <div class="totals-due">
                        <span class="label">Balance Due</span>
                        <span class="value">{{ $currencySymbol }} {{ number_format($invoice->amountDue(), 2) }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── SIGNATURES ──────────────────────────── --}}
        <div class="signatures">
            <div class="sig-box">
                <div class="sig-label">Business Signature</div>
                <div class="sig-line">
                    @if($businessSignature)
                        @if($businessSignature['type'] === 'typed')
                            <div class="sig-typed">{{ $businessSignature['name'] }}</div>
                        @elseif($businessSignature['type'] === 'base64')
                            <img src="{{ $businessSignature['image'] }}" class="sig-image" alt="Signature">
                        @elseif($businessSignature['type'] === 'file')
                            <img src="{{ $businessSignature['path'] }}" class="sig-image" alt="Signature">
                        @endif
                    @endif
                </div>
                <div class="sig-info">
                    <strong>{{ $invoice->business_signature_name ?? 'Pending' }}</strong>
                    @if($invoice->business_signed_at)
                        <br>{{ $invoice->business_signed_at->format('M d, Y') }}
                    @endif
                </div>
            </div>
            <div class="sig-box">
                <div class="sig-label">Client Signature</div>
                <div class="sig-line">
                    @if($clientSignature)
                        @if($clientSignature['type'] === 'typed')
                            <div class="sig-typed">{{ $clientSignature['name'] }}</div>
                        @elseif($clientSignature['type'] === 'base64')
                            <img src="{{ $clientSignature['image'] }}" class="sig-image" alt="Signature">
                        @elseif($clientSignature['type'] === 'file')
                            <img src="{{ $clientSignature['path'] }}" class="sig-image" alt="Signature">
                        @endif
                    @endif
                </div>
                <div class="sig-info">
                    <strong>{{ $invoice->client_signature_name ?? ($invoice->client_signature_required ? 'Authorized Signature' : 'Not Required') }}</strong>
                    @if($invoice->client_signed_at)
                        <br>{{ $invoice->client_signed_at->format('M d, Y') }}
                    @endif
                </div>
            </div>
        </div>

        {{-- ── FOOTER ──────────────────────────────── --}}
        <div class="footer">
            <span>{{ $invoice->invoice_number }} &bull; {{ $business->name }}</span>
            <span>Powered by EsperWorks</span>
        </div>
    </div>
</body>
</html>
