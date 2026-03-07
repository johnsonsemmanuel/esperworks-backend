@extends('emails.layout')

@section('content')
<h1 style="font-size: 24px; font-weight: 700; color: #29235c; margin-bottom: 16px;">
    New Waitlist Signup
</h1>

<p style="font-size: 15px; color: #555; line-height: 1.7; margin-bottom: 20px;">
    A new user has joined the EsperWorks waitlist:
</p>

<div style="background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 24px; border-left: 4px solid #00983a;">
    <p style="font-size: 16px; font-weight: 600; color: #29235c; margin: 0 0 4px 0;">
        {{ $userEmail }}
    </p>
    @if($userPhone)
    <p style="font-size: 14px; color: #555; margin: 4px 0 0 0;">
        Phone/WhatsApp: <strong>{{ $userPhone }}</strong>
    </p>
    @endif
    <p style="font-size: 13px; color: #888; margin: 4px 0 0 0;">
        Signed up at {{ now()->format('M d, Y \a\t h:i A') }}
    </p>
</div>

<p style="font-size: 15px; color: #555; line-height: 1.7; margin-bottom: 8px;">
    <strong>Total waitlist signups:</strong> {{ $totalCount }}
</p>
@endsection
