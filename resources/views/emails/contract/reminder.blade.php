@extends('emails.layout')

@section('title', "Reminder: {$contract->title}")

@section('content')
    <h2>Reminder: Please sign your {{ ucfirst($contract->type) }}</h2>
    <p>Hi {{ $contract->client->name }},</p>
    <p>This is a friendly reminder that <strong>{{ $contract->business->name }}</strong> is waiting for your signature on the following {{ $contract->type }}.</p>

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
            <span class="info-value">GH₵ {{ number_format($contract->value, 2) }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Expires</span>
            <span class="info-value">{{ $contract->expiry_date ? $contract->expiry_date->format('M d, Y') : '—' }}</span>
        </div>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ config('app.frontend_url') }}/contracts/{{ $contract->signing_token }}" class="btn">Review &amp; Sign</a>
    </div>

    <p style="font-size: 12px; color: #94a3b8;">If you have already signed this document, please disregard this reminder.</p>
@endsection
