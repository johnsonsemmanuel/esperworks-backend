@extends('emails.layout')

@section('title', "Payment Receipt: {$invoice->invoice_number}")

@section('content')
    <h2>Payment Received! ✓</h2>
    <p>Hi {{ $invoice->client->name }},</p>
    <p>We've received your payment for invoice <strong>{{ $invoice->invoice_number }}</strong>. Thank you!</p>

    <div class="amount-box">
        <div class="label">Amount Paid</div>
        <div class="amount">GH₵ {{ number_format($invoice->amount_paid, 2) }}</div>
    </div>

    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Invoice Number</span>
            <span class="info-value">{{ $invoice->invoice_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Payment Date</span>
            <span class="info-value">{{ $invoice->paid_at ? $invoice->paid_at->format('M d, Y') : now()->format('M d, Y') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Status</span>
            <span class="info-value" style="color: #00983a;">Paid</span>
        </div>
        <div class="info-row">
            <span class="info-label">From</span>
            <span class="info-value">{{ $invoice->business->name }}</span>
        </div>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ config('app.frontend_url') }}/client/dashboard/invoices" class="btn-outline">View in Dashboard</a>
    </div>

    <p style="font-size: 12px; color: #94a3b8;">A copy of this receipt has been saved to your client portal.</p>
@endsection
