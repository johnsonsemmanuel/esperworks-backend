@extends('emails.layout')

@section('title', "Payment Reminder: {$invoice->invoice_number}")

@section('content')
    <h2>Payment Reminder</h2>
    <p>Hi {{ $invoice->client->name }},</p>
    <p>This is a friendly reminder that payment for invoice <strong>{{ $invoice->invoice_number }}</strong> is
        {{ $invoice->isOverdue() ? 'overdue' : 'due soon' }}.</p>

    <div class="amount-box" style="{{ $invoice->isOverdue() ? 'background: #EF4444;' : '' }}">
        <div class="label">{{ $invoice->isOverdue() ? 'Overdue Amount' : 'Amount Due' }}</div>
        <div class="amount">GH₵ {{ number_format($invoice->amountDue(), 2) }}</div>
    </div>

    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Invoice Number</span>
            <span class="info-value">{{ $invoice->invoice_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Due Date</span>
            <span class="info-value"
                style="{{ $invoice->isOverdue() ? 'color: #EF4444;' : '' }}">{{ $invoice->due_date->format('M d, Y') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">From</span>
            <span class="info-value">{{ $invoice->business->name }}</span>
        </div>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ config('app.frontend_url') }}/invoices/pay/{{ $invoice->signing_token }}" class="btn">Pay Now</a>
    </div>

    <p style="font-size: 12px; color: #94a3b8;">If you've already made this payment, please disregard this reminder.</p>
@endsection