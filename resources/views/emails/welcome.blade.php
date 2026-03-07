@extends('emails.layout')

@section('title', 'Welcome to EsperWorks')

@section('content')
    <h2>Welcome to EsperWorks! 🎉</h2>
    <p>Hi {{ $userName }},</p>
    <p>Thank you for creating your account. <strong>{{ $businessName }}</strong> is now set up and ready to go on EsperWorks — your all-in-one invoicing and finance platform built for African businesses.</p>

    <div class="info-box">
        <p style="font-weight: 700; color: #1e293b; margin: 0 0 12px; font-size: 14px;">Here's what you can do next:</p>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 8px 0; font-size: 13px; color: #475569; border-bottom: 1px solid #f1f5f9;">✅ Complete your business profile in Settings</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-size: 13px; color: #475569; border-bottom: 1px solid #f1f5f9;">🎨 Upload your logo and set brand colors</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-size: 13px; color: #475569; border-bottom: 1px solid #f1f5f9;">👥 Add your first client</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-size: 13px; color: #475569; border-bottom: 1px solid #f1f5f9;">📄 Create and send your first invoice</td>
            </tr>
            <tr>
                <td style="padding: 8px 0; font-size: 13px; color: #475569;">💳 Set up payment receiving with Paystack</td>
            </tr>
        </table>
    </div>

    <div style="text-align: center; margin: 28px 0;">
        <a href="{{ $loginUrl }}" class="btn" style="color: #ffffff;">Go to Your Dashboard →</a>
    </div>

    <p>We're thrilled to have <strong>{{ $businessName }}</strong> on board. If you ever need help, visit <strong>Settings → Support</strong> inside the app.</p>

    <p style="font-size: 12px; color: #94a3b8;">We wish you all the best in growing your business!</p>
@endsection
