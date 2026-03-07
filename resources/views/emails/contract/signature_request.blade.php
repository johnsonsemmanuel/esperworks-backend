@extends('emails.layout')

@section('title', "Signature Requested: {$contract->title}")

@section('content')
    <h2>Your Signature is Requested</h2>
    <p>Hi {{ $contract->client->name }},</p>
    <p><strong>{{ $contract->business->name }}</strong> is requesting your signature on the following {{ $contract->type }}. Please review and sign at your earliest convenience.</p>

    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Title</span>
            <span class="info-value">{{ $contract->title }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Reference</span>
            <span class="info-value">{{ $contract->contract_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Value</span>
            <span class="info-value">{{ $contract->business->currency ?? 'GHS' }} {{ number_format($contract->value, 2) }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Expires</span>
            <span class="info-value">{{ $contract->expiry_date ? $contract->expiry_date->format('M d, Y') : '—' }}</span>
        </div>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ config('app.frontend_url') }}/contracts/{{ $contract->signing_token }}" class="btn">Review &amp; Sign Now</a>
    </div>

    <p style="font-size: 12px; color: #94a3b8;">This is an automated request from {{ $contract->business->name }}. If you have questions about this document, please contact them directly.</p>
@endsection
