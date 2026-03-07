@php
    $branding = $business->branding ?? [];
    $accent = $branding['invoice_accent'] ?? '#29235c';
    $headerBg = $branding['invoice_header_bg'] ?? '#f5f5f5';
    $fontFamily = $branding['invoice_font'] ?? 'Arial, Helvetica, sans-serif';
@endphp

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $contract->contract_number }}</title>
    <style>
        body {
            font-family: {{ $fontFamily }};
            font-size: 12px;
            line-height: 1.4;
            color: #111827;
            margin: 0;
            padding: 24px 32px;
        }
        
        .header {
            margin-bottom: 24px;
            text-align: center;
        }
        
        .business-name {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 4px;
            color: {{ $accent }};
        }
        
        .business-details {
            font-size: 10px;
            color: #6b7280;
            margin-bottom: 16px;
        }
        
        .document-title {
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            margin: 12px 0 4px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: {{ $accent }};
        }
        
        .contract-info {
            margin: 20px 0 24px;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .info-table td {
            border: 1px solid #e5e7eb;
            padding: 7px 10px;
            font-size: 10px;
        }
        
        .info-table .label {
            background-color: {{ $headerBg }};
            font-weight: 600;
            width: 140px;
        }
        
        .content-section {
            margin: 24px 0;
        }
        
        .section-title {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 10px;
            border-bottom: 2px solid {{ $accent }};
            padding-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: {{ $accent }};
        }
        
        .contract-content {
            text-align: justify;
            white-space: pre-wrap;
            line-height: 1.6;
            padding: 18px;
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
        }
        
        .signatures-section {
            margin-top: 40px;
        }
        
        .signatures-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .signatures-table td {
            width: 50%;
            padding: 18px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }
        
        .signature-label {
            font-size: 11px;
            font-weight: 600;
            text-align: center;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #4b5563;
        }
        
        .signature-line {
            border-bottom: 2px solid #111827;
            height: 50px;
            margin-bottom: 8px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .signature-text {
            font-size: 16px;
            font-style: italic;
            color: #111827;
        }
        
        .signature-name {
            font-size: 10px;
            text-align: center;
            font-weight: bold;
            margin-top: 4px;
        }
        
        .signature-date {
            font-size: 9px;
            text-align: center;
            color: #6b7280;
        }
        
        .signature-pending {
            font-size: 10px;
            text-align: center;
            color: #9ca3af;
            font-style: italic;
        }
        
        .status-badge {
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-draft { background-color: #f3f4f6; color: #4b5563; }
        .status-sent { background-color: #dbeafe; color: #1d4ed8; }
        .status-viewed { background-color: #fef3c7; color: #b45309; }
        .status-signed { background-color: #dcfce7; color: #15803d; }
        
        .footer {
            margin-top: 32px;
            padding-top: 16px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 9px;
            color: #9ca3af;
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
    </style>
</head>
<body>
    @if(($business->plan ?? 'free') === 'free')
        <div class="watermark">Powered by EsperWorks - esperworks.com</div>
    @endif

    <!-- Header -->
    <div class="header">
        @if($business->logo)
            <div style="margin-bottom: 15px;">
                <img src="{{ storage_path('app/public/' . $business->logo) }}" alt="{{ $business->name }}" style="max-height: 60px;" />
            </div>
        @endif
        
        <div class="business-name">{{ $business->name }}</div>
        <div class="business-details">
            {{ $business->address }}<br>
            {{ $business->email }}@if($business->phone) &bull; {{ $business->phone }}@endif
            @if($business->website)<br>{{ $business->website }}@endif
        </div>
    </div>

    <!-- Document Title -->
    <div class="document-title">
        {{ strtoupper($contract->type === 'proposal' ? 'PROPOSAL' : 'AGREEMENT') }}
    </div>
    <div style="text-align: center; font-size: 12px; margin-bottom: 20px; color:#4b5563;">
        <strong>{{ $contract->title }}</strong><br>
        {{ $contract->contract_number }} &bull; Prepared for {{ $client->name }}
    </div>

    <!-- Contract Information -->
    <div class="contract-info">
        <table class="info-table">
            <tr>
                <td class="label">Reference</td>
                <td>{{ $contract->contract_number }}</td>
                <td class="label">Type</td>
                <td>{{ ucfirst($contract->type) }}</td>
            </tr>
            <tr>
                <td class="label">Value</td>
                <td>GH₵ {{ number_format($contract->value, 2) }}</td>
                <td class="label">Status</td>
                <td>
                    <span class="status-badge status-{{ $contract->status }}">
                        {{ ucfirst($contract->status) }}
                    </span>
                </td>
            </tr>
            <tr>
                <td class="label">Created</td>
                <td>{{ $contract->created_date ? $contract->created_date->format('M d, Y') : '—' }}</td>
                <td class="label">Expires</td>
                <td>{{ $contract->expiry_date ? $contract->expiry_date->format('M d, Y') : '—' }}</td>
            </tr>
            <tr>
                <td class="label">Sent</td>
                <td>{{ $contract->sent_at ? $contract->sent_at->format('M d, Y') : '—' }}</td>
                <td class="label">Viewed</td>
                <td>{{ $contract->viewed_at ? $contract->viewed_at->format('M d, Y') : '—' }}</td>
            </tr>
        </table>
    </div>

    <!-- Contract Content -->
    <div class="content-section">
        <div class="section-title">TERMS AND CONDITIONS</div>
        <div class="contract-content">{{ $contract->content }}</div>
    </div>

    <!-- Signatures -->
    <div class="signatures-section">
        <div class="section-title">SIGNATURES</div>
        <table class="signatures-table">
            <tr>
                <td>
                    <div class="signature-label">BUSINESS REPRESENTATIVE ({{ $business->name }})</div>
                    <div class="signature-line">
                        @if($contract->business_signature_image)
                            @if(Str::startsWith($contract->business_signature_image, 'typed:'))
                                @php
                                    $parts = explode(':', $contract->business_signature_image);
                                    $name = $parts[2] ?? $contract->business_signature_name;
                                @endphp
                                <div class="signature-text">{{ $name }}</div>
                            @elseif(Str::startsWith($contract->business_signature_image, 'data:image'))
                                <img src="{{ $contract->business_signature_image }}" style="max-height: 40px;" alt="Signature" />
                            @else
                                <img src="{{ storage_path('app/public/' . $contract->business_signature_image) }}" style="max-height: 40px;" alt="Signature" />
                            @endif
                        @endif
                    </div>
                    @if($contract->business_signature_name)
                        <div class="signature-name">{{ $contract->business_signature_name }}</div>
                        <div class="signature-date">Signed on {{ $contract->business_signed_at->format('M d, Y') }}</div>
                    @else
                        <div class="signature-pending">Awaiting signature</div>
                    @endif
                </td>
                <td>
                    <div class="signature-label">CLIENT REPRESENTATIVE ({{ $client->name }})</div>
                    <div class="signature-line">
                        @if($contract->client_signature_image)
                            @if(Str::startsWith($contract->client_signature_image, 'typed:'))
                                @php
                                    $parts = explode(':', $contract->client_signature_image);
                                    $name = $parts[2] ?? $contract->client_signature_name;
                                @endphp
                                <div class="signature-text">{{ $name }}</div>
                            @elseif(Str::startsWith($contract->client_signature_image, 'data:image'))
                                <img src="{{ $contract->client_signature_image }}" style="max-height: 40px;" alt="Signature" />
                            @else
                                <img src="{{ storage_path('app/public/' . $contract->client_signature_image) }}" style="max-height: 40px;" alt="Signature" />
                            @endif
                        @endif
                    </div>
                    @if($contract->client_signature_name)
                        <div class="signature-name">{{ $contract->client_signature_name }}</div>
                        <div class="signature-date">Signed on {{ $contract->client_signed_at->format('M d, Y') }}</div>
                    @else
                        <div class="signature-pending">Awaiting signature</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>This document was generated on {{ now()->format('M d, Y') }} &bull; Powered by EsperWorks</p>
    </div>
</body>
</html>
