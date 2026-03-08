@php
    $branding = $business->branding ?? [];
    $accent = $branding['invoice_accent'] ?? '#1e293b';
    $accentLight = $branding['invoice_header_bg'] ?? '#f1f5f9';
    $fontFamily = "'Inter', 'Helvetica Neue', Arial, sans-serif";
    $logoPath = !empty($business->logo) ? storage_path('app/public/' . $business->logo) : null;
    $clientLogoPath = !empty($client->logo) ? storage_path('app/public/' . $client->logo) : null;
    $isProposal = ($contract->type ?? 'contract') === 'proposal';
    $docLabel = $isProposal ? 'Proposal' : 'Agreement';
    $docLabelUpper = strtoupper($docLabel);
    $now = now();

    // Prepare structured sections from contract data
    $sections = [];
    if (!empty($contract->introduction_message)) {
        $sections[] = ['title' => 'Introduction', 'content' => $contract->introduction_message];
    }
    if (!empty($contract->problem_solution) && is_array($contract->problem_solution)) {
        $ps = $contract->problem_solution;
        $psContent = '';
        if (!empty($ps['problem'])) $psContent .= "<strong>Problem:</strong><br>" . nl2br(e($ps['problem'])) . "<br><br>";
        if (!empty($ps['solution'])) $psContent .= "<strong>Solution:</strong><br>" . nl2br(e($ps['solution']));
        if ($psContent) $sections[] = ['title' => 'Problem & Solution', 'content' => $psContent, 'html' => true];
    }
    if (!empty($contract->scope_of_work) && is_array($contract->scope_of_work)) {
        $scopeHtml = '<ul>';
        foreach ($contract->scope_of_work as $item) {
            $scopeHtml .= '<li>' . e(is_string($item) ? $item : ($item['description'] ?? json_encode($item))) . '</li>';
        }
        $scopeHtml .= '</ul>';
        $sections[] = ['title' => 'Scope of Work', 'content' => $scopeHtml, 'html' => true];
    }
    if (!empty($contract->milestones) && is_array($contract->milestones)) {
        $msHtml = '<table class="milestone-table"><thead><tr><th>Milestone</th><th>Due Date</th><th>Amount</th></tr></thead><tbody>';
        foreach ($contract->milestones as $ms) {
            $msTitle = e(is_string($ms) ? $ms : ($ms['title'] ?? $ms['name'] ?? ''));
            $msDate = $ms['due_date'] ?? $ms['date'] ?? '—';
            $msAmount = isset($ms['amount']) ? 'GH₵ ' . number_format($ms['amount'], 2) : '—';
            $msHtml .= "<tr><td>{$msTitle}</td><td>{$msDate}</td><td>{$msAmount}</td></tr>";
        }
        $msHtml .= '</tbody></table>';
        $sections[] = ['title' => 'Milestones & Deliverables', 'content' => $msHtml, 'html' => true];
    }
    if (!empty($contract->packages) && is_array($contract->packages)) {
        $pkgHtml = '<table class="package-table"><thead><tr><th>Package</th><th>Description</th><th>Price</th></tr></thead><tbody>';
        foreach ($contract->packages as $pkg) {
            $pkgName = e($pkg['name'] ?? '');
            $pkgDesc = e($pkg['description'] ?? '');
            $pkgPrice = isset($pkg['price']) ? 'GH₵ ' . number_format($pkg['price'], 2) : '—';
            $pkgHtml .= "<tr><td><strong>{$pkgName}</strong></td><td>{$pkgDesc}</td><td>{$pkgPrice}</td></tr>";
        }
        $pkgHtml .= '</tbody></table>';
        $sections[] = ['title' => 'Packages', 'content' => $pkgHtml, 'html' => true];
    }
    if (!empty($contract->add_ons) && is_array($contract->add_ons)) {
        $aoHtml = '<ul>';
        foreach ($contract->add_ons as $ao) {
            $aoName = e(is_string($ao) ? $ao : ($ao['name'] ?? ''));
            $aoPrice = isset($ao['price']) ? ' — GH₵ ' . number_format($ao['price'], 2) : '';
            $aoHtml .= "<li><strong>{$aoName}</strong>{$aoPrice}</li>";
        }
        $aoHtml .= '</ul>';
        $sections[] = ['title' => 'Add-Ons', 'content' => $aoHtml, 'html' => true];
    }
    if (!empty($contract->payment_terms) && is_array($contract->payment_terms)) {
        $ptHtml = '<ul>';
        foreach ($contract->payment_terms as $pt) {
            $ptHtml .= '<li>' . e(is_string($pt) ? $pt : ($pt['description'] ?? json_encode($pt))) . '</li>';
        }
        $ptHtml .= '</ul>';
        $sections[] = ['title' => 'Payment Terms', 'content' => $ptHtml, 'html' => true];
    }
    if (!empty($contract->content)) {
        $sections[] = ['title' => 'Terms & Conditions', 'content' => $contract->content];
    }
    if (!empty($contract->terms_lightweight)) {
        $sections[] = ['title' => 'Additional Terms', 'content' => $contract->terms_lightweight];
    }
    if ($contract->confidentiality_enabled) {
        $sections[] = ['title' => 'Confidentiality', 'content' => 'Both parties agree to maintain strict confidentiality regarding all proprietary information, trade secrets, and business processes disclosed during the course of this engagement. This obligation survives the termination of this agreement.'];
    }
    if (!empty($contract->ownership_rights) && is_array($contract->ownership_rights)) {
        $orHtml = '<ul>';
        foreach ($contract->ownership_rights as $or) {
            $orHtml .= '<li>' . e(is_string($or) ? $or : ($or['description'] ?? json_encode($or))) . '</li>';
        }
        $orHtml .= '</ul>';
        $sections[] = ['title' => 'Ownership & Intellectual Property', 'content' => $orHtml, 'html' => true];
    }
    if (!empty($contract->termination_notice_days) || !empty($contract->termination_payment_note)) {
        $termContent = '';
        if (!empty($contract->termination_notice_days)) {
            $termContent .= "Either party may terminate this agreement with <strong>{$contract->termination_notice_days} days</strong> written notice.";
        }
        if (!empty($contract->termination_payment_note)) {
            $termContent .= ($termContent ? '<br><br>' : '') . e($contract->termination_payment_note);
        }
        $sections[] = ['title' => 'Termination', 'content' => $termContent, 'html' => true];
    }

    $sectionCount = count($sections);
    $contentHash = hash('sha256', json_encode([
        'contract_id' => $contract->id,
        'content' => $contract->content,
        'title' => $contract->title,
        'value' => $contract->value,
        'created' => $contract->created_at?->toIso8601String(),
    ]));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $contract->contract_number }} — {{ $contract->title }}</title>
    <style>
        @page {
            size: A4;
            margin: 0;
        }
        @page :first {
            margin: 0;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: {!! $fontFamily !!};
            font-size: 11px;
            color: #1e293b;
            line-height: 1.65;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── COVER PAGE ─────────────────────────── */
        .cover-page {
            width: 210mm;
            min-height: 297mm;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 60px 50px;
            position: relative;
            page-break-after: always;
            background: white;
        }
        .cover-accent-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 8px;
            background: {{ $accent }};
        }
        .cover-accent-bottom {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: {{ $accent }};
        }
        .cover-logo {
            margin-bottom: 40px;
        }
        .cover-logo img {
            max-height: 70px;
            max-width: 220px;
            object-fit: contain;
        }
        .cover-doc-type {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: {{ $accent }};
            margin-bottom: 16px;
        }
        .cover-title {
            font-size: 32px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
            margin-bottom: 12px;
            max-width: 480px;
        }
        .cover-divider {
            width: 60px;
            height: 3px;
            background: {{ $accent }};
            margin: 24px auto;
            border-radius: 2px;
        }
        .cover-ref {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 48px;
        }
        .cover-parties {
            display: flex;
            justify-content: center;
            gap: 80px;
            margin-top: 32px;
        }
        .cover-party {
            text-align: center;
        }
        .cover-party-label {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 8px;
        }
        .cover-party-name {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
        }
        .cover-party-email {
            font-size: 11px;
            color: #64748b;
        }
        .cover-date {
            margin-top: 48px;
            font-size: 12px;
            color: #94a3b8;
        }
        .cover-value {
            margin-top: 32px;
            padding: 16px 40px;
            background: {{ $accentLight }};
            border-radius: 8px;
            display: inline-block;
        }
        .cover-value-label {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 4px;
        }
        .cover-value-amount {
            font-size: 24px;
            font-weight: 800;
            color: {{ $accent }};
        }

        /* ── CONTENT PAGES ──────────────────────── */
        .content-page {
            padding: 50px 56px 70px;
            position: relative;
            min-height: 100%;
        }

        /* Running header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 16px;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 32px;
        }
        .page-header-left {
            font-size: 10px;
            font-weight: 600;
            color: {{ $accent }};
        }
        .page-header-right {
            font-size: 10px;
            color: #94a3b8;
        }

        /* Table of Contents */
        .toc {
            margin-bottom: 40px;
        }
        .toc-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 20px;
            letter-spacing: -0.3px;
        }
        .toc-item {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px dotted #cbd5e1;
        }
        .toc-item-num {
            font-weight: 700;
            color: {{ $accent }};
            margin-right: 12px;
            min-width: 24px;
        }
        .toc-item-title {
            flex: 1;
            font-weight: 500;
            color: #334155;
        }

        /* Document Info Block */
        .doc-info {
            background: {{ $accentLight }};
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 36px;
            border-left: 4px solid {{ $accent }};
        }
        .doc-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 32px;
        }
        .doc-info-item {
            display: flex;
            flex-direction: column;
        }
        .doc-info-label {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 2px;
        }
        .doc-info-value {
            font-size: 12px;
            font-weight: 600;
            color: #0f172a;
        }

        /* Sections */
        .section {
            margin-bottom: 28px;
            page-break-inside: avoid;
        }
        .section-number {
            font-size: 10px;
            font-weight: 800;
            color: {{ $accent }};
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .section-title {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
            letter-spacing: -0.2px;
        }
        .section-rule {
            width: 40px;
            height: 2px;
            background: {{ $accent }};
            margin-bottom: 14px;
            border-radius: 1px;
        }
        .section-body {
            font-size: 11px;
            color: #334155;
            line-height: 1.75;
            text-align: justify;
        }
        .section-body p { margin-bottom: 10px; }
        .section-body ul { padding-left: 20px; margin-bottom: 10px; }
        .section-body li { margin-bottom: 6px; }
        .section-body strong { color: #0f172a; }

        /* Tables inside sections */
        .milestone-table, .package-table {
            width: 100%;
            border-collapse: collapse;
            margin: 12px 0;
            font-size: 11px;
        }
        .milestone-table th, .package-table th {
            background: {{ $accent }};
            color: white;
            padding: 10px 14px;
            text-align: left;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .milestone-table td, .package-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }
        .milestone-table tr:nth-child(even) td,
        .package-table tr:nth-child(even) td {
            background: #f8fafc;
        }

        /* ── SIGNATURE PAGE ─────────────────────── */
        .signature-page {
            page-break-before: always;
            padding: 50px 56px 40px;
        }
        .sig-title {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .sig-subtitle {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 32px;
        }
        .sig-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
        }
        .sig-block {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
        }
        .sig-block-label {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 16px;
            text-align: center;
        }
        .sig-line {
            border-bottom: 2px solid #0f172a;
            min-height: 56px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding-bottom: 6px;
            margin-bottom: 12px;
        }
        .sig-drawn img {
            max-height: 48px;
            display: block;
            margin: 0 auto;
        }
        .sig-typed {
            font-size: 22px;
            font-style: italic;
            color: #0f172a;
            text-align: center;
            font-family: 'Times New Roman', serif;
        }
        .sig-name {
            font-size: 12px;
            font-weight: 700;
            text-align: center;
            color: #0f172a;
            margin-bottom: 2px;
        }
        .sig-date {
            font-size: 10px;
            text-align: center;
            color: #64748b;
        }
        .sig-pending {
            font-size: 11px;
            text-align: center;
            color: #94a3b8;
            font-style: italic;
            padding: 16px 0;
        }

        /* ── AUDIT TRAIL ────────────────────────── */
        .audit-trail {
            margin-top: 40px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
        }
        .audit-trail-header {
            background: #f1f5f9;
            padding: 12px 20px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #475569;
            border-bottom: 1px solid #e2e8f0;
        }
        .audit-trail-body {
            padding: 16px 20px;
        }
        .audit-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            font-size: 10px;
            border-bottom: 1px solid #f1f5f9;
        }
        .audit-row:last-child { border-bottom: none; }
        .audit-label {
            color: #64748b;
            font-weight: 500;
        }
        .audit-value {
            color: #0f172a;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            font-size: 9px;
        }

        /* ── FOOTER ─────────────────────────────── */
        .page-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 40px;
            padding: 0 56px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 9px;
            color: #94a3b8;
            border-top: 1px solid #f1f5f9;
        }

        /* Watermark for free plan */
        .watermark {
            position: fixed;
            bottom: 12px;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            color: #d1d5db;
        }

        /* Status badge */
        .status-badge {
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
        .status-signed { background: #dcfce7; color: #15803d; }
        .status-accepted { background: #dcfce7; color: #15803d; }
        .status-rejected { background: #fee2e2; color: #dc2626; }
        .status-expired { background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
    @if(($business->plan ?? 'free') === 'free')
        <div class="watermark">Powered by EsperWorks &mdash; esperworks.com</div>
    @endif

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- COVER PAGE                                         --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <div class="cover-page">
        <div class="cover-accent-bar"></div>
        <div class="cover-accent-bottom"></div>

        @if($logoPath && file_exists($logoPath))
            <div class="cover-logo">
                <img src="{{ $logoPath }}" alt="{{ $business->name }}">
            </div>
        @endif

        <div class="cover-doc-type">{{ $docLabelUpper }}</div>

        <h1 class="cover-title">{{ $contract->title }}</h1>

        <div class="cover-divider"></div>

        <div class="cover-ref">
            {{ $contract->contract_number }}
            @if($contract->created_date)
                &bull; {{ $contract->created_date->format('F j, Y') }}
            @endif
        </div>

        @if($contract->value > 0)
            <div class="cover-value">
                <div class="cover-value-label">{{ $isProposal ? 'Proposed Value' : 'Contract Value' }}</div>
                <div class="cover-value-amount">GH₵ {{ number_format($contract->value, 2) }}</div>
            </div>
        @endif

        <div class="cover-parties">
            <div class="cover-party">
                <div class="cover-party-label">Prepared By</div>
                <div class="cover-party-name">{{ $business->name }}</div>
                <div class="cover-party-email">{{ $business->email }}</div>
            </div>
            <div class="cover-party">
                <div class="cover-party-label">Prepared For</div>
                <div class="cover-party-name">{{ $client->name }}</div>
                <div class="cover-party-email">{{ $client->email ?? '' }}</div>
            </div>
        </div>

        <div class="cover-date">
            @if($contract->expiry_date)
                Valid until {{ $contract->expiry_date->format('F j, Y') }}
            @else
                Issued {{ $contract->created_date ? $contract->created_date->format('F j, Y') : $now->format('F j, Y') }}
            @endif
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- TABLE OF CONTENTS + DOCUMENT INFO                  --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <div class="content-page">
        <div class="page-header">
            <div class="page-header-left">{{ $business->name }}</div>
            <div class="page-header-right">{{ $contract->contract_number }}</div>
        </div>

        {{-- Document Info --}}
        <div class="doc-info">
            <div class="doc-info-grid">
                <div class="doc-info-item">
                    <span class="doc-info-label">Reference</span>
                    <span class="doc-info-value">{{ $contract->contract_number }}</span>
                </div>
                <div class="doc-info-item">
                    <span class="doc-info-label">Document Type</span>
                    <span class="doc-info-value">{{ ucfirst($contract->type ?? 'contract') }}</span>
                </div>
                <div class="doc-info-item">
                    <span class="doc-info-label">Value</span>
                    <span class="doc-info-value">GH₵ {{ number_format($contract->value, 2) }}</span>
                </div>
                <div class="doc-info-item">
                    <span class="doc-info-label">Status</span>
                    <span class="doc-info-value">
                        <span class="status-badge status-{{ $contract->status }}">{{ ucfirst($contract->status) }}</span>
                    </span>
                </div>
                <div class="doc-info-item">
                    <span class="doc-info-label">Created</span>
                    <span class="doc-info-value">{{ $contract->created_date ? $contract->created_date->format('M d, Y') : '—' }}</span>
                </div>
                <div class="doc-info-item">
                    <span class="doc-info-label">Expires</span>
                    <span class="doc-info-value">{{ $contract->expiry_date ? $contract->expiry_date->format('M d, Y') : '—' }}</span>
                </div>
                <div class="doc-info-item">
                    <span class="doc-info-label">Client</span>
                    <span class="doc-info-value">{{ $client->name }}</span>
                </div>
                <div class="doc-info-item">
                    <span class="doc-info-label">Business</span>
                    <span class="doc-info-value">{{ $business->name }}</span>
                </div>
            </div>
        </div>

        @if($sectionCount > 2)
        {{-- Table of Contents --}}
        <div class="toc">
            <div class="toc-title">Table of Contents</div>
            @foreach($sections as $idx => $sec)
                <div class="toc-item">
                    <span class="toc-item-num">{{ $idx + 1 }}.</span>
                    <span class="toc-item-title">{{ $sec['title'] }}</span>
                </div>
            @endforeach
            <div class="toc-item">
                <span class="toc-item-num">{{ $sectionCount + 1 }}.</span>
                <span class="toc-item-title">Signatures</span>
            </div>
        </div>
        @endif

        {{-- ══════════════════════════════════════════════════ --}}
        {{-- BODY SECTIONS                                      --}}
        {{-- ══════════════════════════════════════════════════ --}}
        @foreach($sections as $idx => $sec)
            <div class="section">
                <div class="section-number">Section {{ $idx + 1 }}</div>
                <div class="section-title">{{ $sec['title'] }}</div>
                <div class="section-rule"></div>
                <div class="section-body">
                    @if(!empty($sec['html']))
                        {!! $sec['content'] !!}
                    @else
                        {!! nl2br(e($sec['content'])) !!}
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- ══════════════════════════════════════════════════ --}}
    {{-- SIGNATURE PAGE                                     --}}
    {{-- ══════════════════════════════════════════════════ --}}
    <div class="signature-page">
        <div class="page-header">
            <div class="page-header-left">{{ $business->name }}</div>
            <div class="page-header-right">{{ $contract->contract_number }} &mdash; Signatures</div>
        </div>

        <div class="sig-title">Signatures</div>
        <div class="sig-subtitle">
            By signing below, both parties agree to the terms and conditions outlined in this {{ strtolower($docLabel) }}.
        </div>

        <div class="sig-grid">
            {{-- Business Signature --}}
            <div class="sig-block">
                <div class="sig-block-label">{{ $business->name }}</div>
                <div class="sig-line">
                    @if($contract->business_signature_image)
                        @if(Str::startsWith($contract->business_signature_image, 'typed:'))
                            @php
                                $parts = explode(':', $contract->business_signature_image);
                                $typedName = $parts[2] ?? $contract->business_signature_name;
                            @endphp
                            <div class="sig-typed">{{ $typedName }}</div>
                        @elseif(Str::startsWith($contract->business_signature_image, 'data:image'))
                            <div class="sig-drawn"><img src="{{ $contract->business_signature_image }}" alt="Signature"></div>
                        @else
                            <div class="sig-drawn"><img src="{{ storage_path('app/public/' . $contract->business_signature_image) }}" alt="Signature"></div>
                        @endif
                    @endif
                </div>
                @if($contract->business_signature_name)
                    <div class="sig-name">{{ $contract->business_signature_name }}</div>
                    <div class="sig-date">Signed {{ $contract->business_signed_at ? $contract->business_signed_at->format('M d, Y \a\t g:i A') : '' }}</div>
                @else
                    <div class="sig-pending">Awaiting signature</div>
                @endif
            </div>

            {{-- Client Signature --}}
            <div class="sig-block">
                <div class="sig-block-label">{{ $client->name }}</div>
                <div class="sig-line">
                    @if($contract->client_signature_image)
                        @if(Str::startsWith($contract->client_signature_image, 'typed:'))
                            @php
                                $parts = explode(':', $contract->client_signature_image);
                                $typedName = $parts[2] ?? $contract->client_signature_name;
                            @endphp
                            <div class="sig-typed">{{ $typedName }}</div>
                        @elseif(Str::startsWith($contract->client_signature_image, 'data:image'))
                            <div class="sig-drawn"><img src="{{ $contract->client_signature_image }}" alt="Signature"></div>
                        @else
                            <div class="sig-drawn"><img src="{{ storage_path('app/public/' . $contract->client_signature_image) }}" alt="Signature"></div>
                        @endif
                    @endif
                </div>
                @if($contract->client_signature_name)
                    <div class="sig-name">{{ $contract->client_signature_name }}</div>
                    <div class="sig-date">Signed {{ $contract->client_signed_at ? $contract->client_signed_at->format('M d, Y \a\t g:i A') : '' }}</div>
                @else
                    <div class="sig-pending">Awaiting signature</div>
                @endif
            </div>
        </div>

        {{-- Audit Trail --}}
        <div class="audit-trail">
            <div class="audit-trail-header">Document Audit Trail</div>
            <div class="audit-trail-body">
                <div class="audit-row">
                    <span class="audit-label">Document ID</span>
                    <span class="audit-value">{{ $contract->contract_number }}</span>
                </div>
                <div class="audit-row">
                    <span class="audit-label">Created</span>
                    <span class="audit-value">{{ $contract->created_at ? $contract->created_at->format('M d, Y H:i:s T') : '—' }}</span>
                </div>
                @if($contract->sent_at)
                <div class="audit-row">
                    <span class="audit-label">Sent to Client</span>
                    <span class="audit-value">{{ $contract->sent_at->format('M d, Y H:i:s T') }}</span>
                </div>
                @endif
                @if($contract->viewed_at)
                <div class="audit-row">
                    <span class="audit-label">Viewed by Client</span>
                    <span class="audit-value">{{ $contract->viewed_at->format('M d, Y H:i:s T') }}</span>
                </div>
                @endif
                @if($contract->business_signed_at)
                <div class="audit-row">
                    <span class="audit-label">Business Signed</span>
                    <span class="audit-value">{{ $contract->business_signed_at->format('M d, Y H:i:s T') }}</span>
                </div>
                @endif
                @if($contract->client_signed_at)
                <div class="audit-row">
                    <span class="audit-label">Client Signed</span>
                    <span class="audit-value">{{ $contract->client_signed_at->format('M d, Y H:i:s T') }}</span>
                </div>
                @endif
                @if(!empty($contract->client_response_ip))
                <div class="audit-row">
                    <span class="audit-label">Client IP</span>
                    <span class="audit-value">{{ $contract->client_response_ip }}</span>
                </div>
                @endif
                <div class="audit-row">
                    <span class="audit-label">Content Hash (SHA-256)</span>
                    <span class="audit-value">{{ substr($contentHash, 0, 16) }}...{{ substr($contentHash, -8) }}</span>
                </div>
                <div class="audit-row">
                    <span class="audit-label">Generated</span>
                    <span class="audit-value">{{ $now->format('M d, Y H:i:s T') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Footer --}}
    <div class="page-footer">
        <span>{{ $contract->contract_number }} &bull; {{ $business->name }}</span>
        <span>Powered by EsperWorks</span>
    </div>
</body>
</html>
