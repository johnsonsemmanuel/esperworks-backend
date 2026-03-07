@extends('emails.layout')

@section('title', "{$contract->title} - Signed")

@section('content')
    <h2>{{ ucfirst($contract->type) }} Signed ✓</h2>
    <p>Great news! The {{ $contract->type }} <strong>"{{ $contract->title }}"</strong> has been fully signed by all parties.</p>

    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Document</span>
            <span class="info-value">{{ $contract->title }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Reference</span>
            <span class="info-value">{{ $contract->contract_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Business Signed</span>
            <span class="info-value" style="color: #00983a;">✓ {{ $contract->business_signature_name }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Client Signed</span>
            <span class="info-value" style="color: #00983a;">✓ {{ $contract->client_signature_name }}</span>
        </div>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ config('app.frontend_url') }}/client/dashboard/contracts" class="btn-outline">View in Dashboard</a>
    </div>
@endsection
