<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice Preview - {{ $template->name }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: {{ $template->getFontSettings()['body_font'] ?? 'Inter' }}, sans-serif;
            font-size: {{ $template->getFontSettings()['body_size'] ?? 12 }}px;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            line-height: 1.5;
            background: {{ $template->getColorScheme()['background_color'] ?? '#ffffff' }};
        }

        .page {
            padding: 40px;
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid {{ $template->getColorScheme()['primary_color'] ?? '#00983a' }};
        }

        .logo-section {
            flex: 1;
        }

        .logo {
            width: 120px;
            height: 60px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: {{ $template->getColorScheme()['header_background'] ?? '#f8fafc' }};
            border-radius: 8px;
            font-weight: bold;
            color: {{ $template->getColorScheme()['primary_color'] ?? '#00983a' }};
        }

        .business-info {
            margin-top: 10px;
        }

        .business-name {
            font-size: {{ $template->getFontSettings()['header_size'] ?? 24 }}px;
            font-weight: 700;
            color: {{ $template->getColorScheme()['primary_color'] ?? '#00983a' }};
            margin-bottom: 5px;
        }

        .business-details {
            font-size: 12px;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            opacity: 0.8;
            line-height: 1.4;
        }

        .invoice-details {
            text-align: right;
            flex: 1;
        }

        .invoice-title {
            font-size: {{ $template->getFontSettings()['title_size'] ?? 18 }}px;
            font-weight: 600;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            margin-bottom: 15px;
        }

        .invoice-meta {
            font-size: 12px;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            opacity: 0.8;
        }

        .invoice-meta div {
            margin-bottom: 5px;
        }

        .client-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: {{ $template->getFontSettings()['subtitle_size'] ?? 14 }}px;
            font-weight: 600;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .client-info {
            font-size: 12px;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            opacity: 0.8;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .items-table th {
            background: {{ $template->getColorScheme()['header_background'] ?? '#f8fafc' }};
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            font-weight: 600;
            text-align: left;
            padding: 12px;
            border: 1px solid {{ $template->getColorScheme()['border_color'] ?? '#e5e7eb' }};
        }

        .items-table td {
            padding: 12px;
            border: 1px solid {{ $template->getColorScheme()['border_color'] ?? '#e5e7eb' }};
        }

        .items-table .text-right {
            text-align: right;
        }

        .totals-section {
            margin-bottom: 30px;
        }

        .totals-table {
            width: 300px;
            margin-left: auto;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 8px 12px;
            border: 1px solid {{ $template->getColorScheme()['border_color'] ?? '#e5e7eb' }};
        }

        .totals-table .label {
            font-weight: 500;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            opacity: 0.8;
        }

        .totals-table .value {
            text-align: right;
            font-weight: 600;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
        }

        .totals-table .total-row {
            background: {{ $template->getColorScheme()['header_background'] ?? '#f8fafc' }};
            font-weight: 700;
            font-size: 14px;
        }

        .notes-section {
            margin-bottom: 30px;
        }

        .notes-content {
            font-size: 12px;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            opacity: 0.8;
            padding: 15px;
            background: {{ $template->getColorScheme()['header_background'] ?? '#f8fafc' }};
            border-radius: 8px;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid {{ $template->getColorScheme()['border_color'] ?? '#e5e7eb' }};
            font-size: 10px;
            color: {{ $template->getColorScheme()['text_color'] ?? '#1e293b' }};
            opacity: 0.6;
            text-align: center;
        }

        .custom-field {
            margin-bottom: 10px;
        }

        .custom-field-label {
            font-weight: 600;
            margin-bottom: 2px;
        }

        .custom-field-value {
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <div class="page">
        <!-- Header Section -->
        <div class="header">
            <div class="logo-section">
                @if($template->getHeaderSettings()['show_logo'])
                    <div class="logo">
                        @if($business->logo)
                            <img src="{{ Storage::url($business->logo) }}" alt="{{ $business->name }}" style="max-width: 100%; max-height: 100%;">
                        @else
                            {{ substr($business->name, 0, 3) }}
                        @endif
                    </div>
                @endif
                
                <div class="business-info">
                    @if($template->getHeaderSettings()['show_business_name'])
                        <div class="business-name">{{ $business->name }}</div>
                    @endif
                    
                    @if($template->getHeaderSettings()['show_business_address'])
                        <div class="business-details">
                            {{ $business->address }}<br>
                            @if($business->city) {{ $business->city }}, @endif {{ $business->country }}
                        </div>
                    @endif
                    
                    @if($template->getHeaderSettings()['show_business_phone'])
                        <div class="business-details">{{ $business->phone }}</div>
                    @endif
                    
                    @if($template->getHeaderSettings()['show_business_email'])
                        <div class="business-details">{{ $business->email }}</div>
                    @endif
                    
                    @if($template->getHeaderSettings()['show_tax_id'] && $business->tin)
                        <div class="business-details">TIN: {{ $business->tin }}</div>
                    @endif
                </div>
            </div>

            <div class="invoice-details">
                <div class="invoice-title">INVOICE</div>
                <div class="invoice-meta">
                    <div><strong>Number:</strong> {{ $invoice->invoice_number }}</div>
                    <div><strong>Date:</strong> {{ $invoice->issue_date }}</div>
                    <div><strong>Due Date:</strong> {{ $invoice->due_date }}</div>
                </div>
            </div>
        </div>

        <!-- Client Section -->
        <div class="client-section">
            <div class="section-title">Bill To</div>
            <div class="client-info">
                <div><strong>{{ $client->name }}</strong></div>
                @if($client->address) <div>{{ $client->address }}</div> @endif
                @if($client->city || $client->country) <div>{{ $client->city }} {{ $client->country }}</div> @endif
                @if($client->email) <div>{{ $client->email }}</div> @endif
                @if($client->phone) <div>{{ $client->phone }}</div> @endif
            </div>
        </div>

        <!-- Custom Fields -->
        @if($template->getCustomFields())
            <div class="custom-fields-section">
                @foreach($template->getCustomFields() as $field)
                    <div class="custom-field">
                        <div class="custom-field-label">{{ $field['label'] ?? 'Custom Field' }}</div>
                        <div class="custom-field-value">{{ $field['value'] ?? '' }}</div>
                    </div>
                @endforeach
            </div>
        @endif

        <!-- Items Table -->
        <table class="items-table">
            <thead>
                <tr>
                    @if($template->getItemSettings()['show_item_numbers'])
                        <th>{{ $template->getItemSettings()['item_name_label'] ?? 'Item/Service' }}</th>
                    @endif
                    @if($template->getItemSettings()['show_descriptions'])
                        <th>Description</th>
                    @endif
                    @if($template->getItemSettings()['show_quantities'])
                        <th class="text-right">{{ $template->getItemSettings()['quantity_label'] ?? 'Qty' }}</th>
                    @endif
                    @if($template->getItemSettings()['show_rates'])
                        <th class="text-right">{{ $template->getItemSettings()['rate_label'] ?? 'Rate' }}</th>
                    @endif
                    @if($template->getItemSettings()['show_amounts'])
                        <th class="text-right">{{ $template->getItemSettings()['amount_label'] ?? 'Amount' }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        @if($template->getItemSettings()['show_item_numbers'])
                            <td>{{ $loop->iteration }}</td>
                        @endif
                        @if($template->getItemSettings()['show_descriptions'])
                            <td>{{ $item->description }}</td>
                        @endif
                        @if($template->getItemSettings()['show_quantities'])
                            <td class="text-right">{{ $item->quantity }}</td>
                        @endif
                        @if($template->getItemSettings()['show_rates'])
                            <td class="text-right">GH₵ {{ number_format($item->rate, 2) }}</td>
                        @endif
                        @if($template->getItemSettings()['show_amounts'])
                            <td class="text-right">GH₵ {{ number_format($item->total, 2) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals Section -->
        @if($template->getTotalSettings()['show_subtotal'] || $template->getTotalSettings()['show_vat'] || $template->getTotalSettings()['show_total'])
            <div class="totals-section">
                <table class="totals-table">
                    @if($template->getTotalSettings()['show_subtotal'])
                        <tr>
                            <td class="label">{{ $template->getTotalSettings()['subtotal_label'] ?? 'Subtotal' }}</td>
                            <td class="value">GH₵ {{ number_format($invoice->subtotal, 2) }}</td>
                        </tr>
                    @endif
                    
                    @if($template->getTotalSettings()['show_vat'])
                        <tr>
                            <td class="label">{{ $template->getTotalSettings()['vat_label'] ?? 'VAT' }} ({{ $invoice->vat_rate }}%)</td>
                            <td class="value">GH₵ {{ number_format($invoice->vat_amount, 2) }}</td>
                        </tr>
                    @endif
                    
                    @if($template->getTotalSettings()['show_total'])
                        <tr class="total-row">
                            <td class="label">{{ $template->getTotalSettings()['total_label'] ?? 'Total' }}</td>
                            <td class="value">GH₵ {{ number_format($invoice->total, 2) }}</td>
                        </tr>
                    @endif
                </table>
            </div>
        @endif

        <!-- Notes Section -->
        @if($template->getNotesSettings()['show_notes'] && $invoice->notes)
            <div class="notes-section">
                <div class="section-title">Notes</div>
                <div class="notes-content">{{ $invoice->notes }}</div>
            </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            @php
                $footerSettings = $template->getFooterSettings();
                $footerText = $footerSettings['custom_footer_text'] ?? '';
            @endphp
            <div>{{ $footerText ?: 'Thank you for your business. Payment is due by the date shown above. For payment instructions or questions, please contact us using the details on this invoice.' }}</div>
            <div>Page 1 of 1</div>
        </div>
    </div>
</body>
</html>
