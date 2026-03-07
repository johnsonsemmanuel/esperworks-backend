@extends('emails.layout')

@section('title', "Payment Reminder: {{ $invoice->invoice_number }}")

@section('content')
    <h2>Payment Reminder Notification</h2>
    <p>Hi {{ $invoice->business->name }},</p>
    <p>This is to inform you that a payment reminder has been sent to your client for the following invoice:</p>

    <div class="amount-box">
        <div class="label">Outstanding Amount</div>
        <div class="amount">GH₵ {{ number_format($invoice->total - $invoice->amount_paid, 2) }}</div>
    </div>

    <div class="info-box">
        <div class="info-row">
            <span class="info-label">Invoice Number</span>
            <span class="info-value">{{ $invoice->invoice_number }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Client</span>
            <span class="info-value">{{ $invoice->client->name }} ({{ $invoice->client->email }})</span>
        </div>
        <div class="info-row">
            <span class="info-label">Due Date</span>
            <span class="info-value">{{ $invoice->due_date->format('M d, Y') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Status</span>
            <span class="info-value">{{ ucfirst($invoice->status) }}</span>
        </div>
    </div>

    @if($invoice->due_date < now())
        <p style="color: #dc2626; font-weight: bold;">⚠️ This invoice is overdue!</p>
    @else
        <p>💡 This reminder was sent to help ensure timely payment.</p>
    @endif

    <p style="font-size: 12px; color: #94a3b8;">You can view all your invoices and payment status in your dashboard. If you have any questions, please contact your client directly.</p>
@endsection
