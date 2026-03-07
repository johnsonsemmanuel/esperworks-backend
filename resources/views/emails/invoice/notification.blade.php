@extends('emails.layout')

@section('title', "Invoice Notification: {{ $invoice->invoice_number }}")

@section('content')
    <h2>Invoice Sent Successfully</h2>
    <p>Hi {{ $invoice->business->name }},</p>
    <p>Good news! Your invoice has been sent to the client.</p>

    <div class="amount-box">
        <div class="label">Invoice Amount</div>
        <div class="amount">GH₵ {{ number_format($invoice->total, 2) }}</div>
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
            <span class="info-label">Issue Date</span>
            <span class="info-value">{{ $invoice->issue_date->format('M d, Y') }}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Due Date</span>
            <span class="info-value">{{ $invoice->due_date->format('M d, Y') }}</span>
        </div>
    </div>

    <p style="font-size: 12px; color: #94a3b8;">You can view all your invoices in the dashboard. If you have any questions, feel free to reach out to your client directly.</p>
@endsection
