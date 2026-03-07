@extends('emails.layout')

@section('title', "Invoice {$invoice->invoice_number}")

@section('content')
    <h2>New Invoice from {{ $invoice->business->name }}</h2>
    <p>Hi {{ $invoice->client->name }},</p>
    <p>You have received a new invoice. Please find the details below:</p>

    <div class="amount-box">
        <div class="label">Amount Due</div>
        <div class="amount">GH₵ {{ number_format($invoice->total, 2) }}</div>
    </div>

    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Invoice Number</span>
            <span class="info-value">{{ $invoice->invoice_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Issue Date</span>
            <span class="info-value">{{ $invoice->issue_date->format('M d, Y') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Due Date</span>
            <span class="info-value">{{ $invoice->due_date->format('M d, Y') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">From</span>
            <span class="info-value">{{ $invoice->business->name }}</span>
        </div>
    </div>

    @if($invoice->client_signature_required)
        <p>This invoice requires your signature. Please click the button below to open the invoice and complete payment.</p>
    @endif

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ config('app.frontend_url') }}/invoices/pay/{{ $invoice->signing_token }}" class="btn">
            {{ $invoice->client_signature_required ? 'Open Invoice & Pay' : 'Pay Invoice' }}
        </a>
    </div>

    <p style="font-size: 12px; color: #94a3b8;">If you have any questions about this invoice, please contact
        {{ $invoice->business->name }} at {{ $invoice->business->email }}.</p>
@endsection