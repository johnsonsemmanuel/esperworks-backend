@extends('emails.layout')

@section('title', "You're Invited to {$business->name}'s Client Portal")

@section('content')
    <h2>Welcome to {{ $business->name }}'s Client Portal</h2>
    <p>Hi {{ $client->name }},</p>
    <p><strong>{{ $business->name }}</strong> has invited you to their client portal on EsperWorks. You can now view invoices, sign contracts, track payments, and manage your business relationship — all in one place.</p>

    <div class="info-box">
        <p style="font-size: 13px; font-weight: 600; color: #1e293b; margin: 0 0 12px;">Your Login Credentials</p>
        <div class="credential-box">
            <p>Email</p>
            <div class="value">{{ $client->email }}</div>
        </div>
        <div class="credential-box">
            <p>Temporary Password</p>
            <div class="value">{{ $temporaryPassword }}</div>
        </div>
        <p style="font-size: 11px; color: #F97316; margin: 12px 0 0;">⚠ You will be asked to change your password on first login.</p>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ $loginUrl }}" class="btn">Sign In to Your Portal</a>
    </div>

    <p>Once signed in, you can:</p>
    <ul style="font-size: 13px; color: #475569; line-height: 2;">
        <li>View and download invoices</li>
        <li>Sign contracts and proposals</li>
        <li>Track payment history</li>
        <li>Make payments directly through the portal</li>
    </ul>

    <p style="font-size: 12px; color: #94a3b8;">This invitation was sent by {{ $business->name }}. If you didn't expect this, you can safely ignore it.</p>
@endsection
