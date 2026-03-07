@extends('emails.layout')

@section('title', 'Reset Your Password')

@section('content')
    <h2>Reset Your Password</h2>
    <p>Hi {{ $userName }},</p>
    <p>We received a request to reset your EsperWorks password. Click the button below to set a new password:</p>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ $resetUrl }}" class="btn">Reset Password</a>
    </div>

    <p style="font-size: 12px; color: #94a3b8;">This link will expire in 60 minutes. If you didn't request a password reset, you can safely ignore this email.</p>

    <div class="info-box">
        <p style="font-size: 12px; color: #94a3b8; margin: 0;">If the button doesn't work, copy and paste this URL into your browser:</p>
        <p style="font-size: 11px; word-break: break-all; color: #00983a; margin: 8px 0 0;">{{ $resetUrl }}</p>
    </div>
@endsection
