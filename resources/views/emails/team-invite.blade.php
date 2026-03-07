@extends('emails.layout')

@section('title', "You've been invited to join {$business->name}")

@section('content')
    <h2>Welcome to {{ $business->name }} on EsperWorks</h2>
    <p>Hi {{ $memberName }},</p>
    <p><strong>{{ $business->name }}</strong> has invited you to join their team on EsperWorks as a <strong>{{ ucfirst($role) }}</strong>. You can now help manage invoices, contracts, expenses, and more.</p>

    <div class="info-box">
        <p style="font-size: 13px; font-weight: 600; color: #1e293b; margin: 0 0 12px;">Your Login Credentials</p>
        <div class="credential-box">
            <p>Email</p>
            <div class="value">{{ $email }}</div>
        </div>
        <div class="credential-box">
            <p>Temporary Password</p>
            <div class="value">{{ $temporaryPassword }}</div>
        </div>
        <p style="font-size: 11px; color: #F97316; margin: 12px 0 0;">⚠ You will be asked to change your password on first login.</p>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ $loginUrl }}" class="btn">Sign In to EsperWorks</a>
    </div>

    <p>As a <strong>{{ ucfirst($role) }}</strong>, you can:</p>
    <ul style="font-size: 13px; color: #475569; line-height: 2;">
        @if($role === 'admin')
            <li>Full access to all business features</li>
            <li>Manage invoices, contracts, and clients</li>
            <li>View financial reports and expenses</li>
            <li>Manage team members</li>
        @elseif($role === 'accountant')
            <li>Create and manage invoices</li>
            <li>Track expenses and payments</li>
            <li>View financial reports</li>
        @elseif($role === 'staff')
            <li>Create invoices and contracts</li>
            <li>Manage clients</li>
            <li>Track expenses</li>
        @else
            <li>View invoices and contracts</li>
            <li>View client information</li>
            <li>View financial summaries</li>
        @endif
    </ul>

    <p style="font-size: 12px; color: #94a3b8;">This invitation was sent by {{ $business->name }}. If you didn't expect this, you can safely ignore it.</p>
@endsection
