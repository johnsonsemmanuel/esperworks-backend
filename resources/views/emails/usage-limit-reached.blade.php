@php
    $businessName = $business->name ?? 'your business';
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Usage limit reached</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color:#f4f4f5; padding:24px;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
    <tr>
        <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:600px; background:#ffffff; border-radius:12px; padding:24px;">
                <tr>
                    <td>
                        <h1 style="font-size:20px; margin:0 0 12px; color:#18181b;">
                            You’ve reached your {{ $resource }} limit
                        </h1>
                        <p style="font-size:14px; color:#3f3f46; line-height:1.5; margin:0 0 12px;">
                            Hi {{ $business->owner->name ?? 'there' }},
                        </p>
                        <p style="font-size:14px; color:#3f3f46; line-height:1.6; margin:0 0 12px;">
                            {{ ucfirst($resource) }} usage for <strong>{{ $businessName }}</strong> has reached
                            <strong>{{ $usage }}</strong> out of <strong>{{ $limit }}</strong> on your current plan.
                        </p>
                        <p style="font-size:14px; color:#3f3f46; line-height:1.6; margin:0 0 12px;">
                            To keep workflows uninterrupted, you can free up space or upgrade to a plan with higher limits.
                        </p>
                        <p style="text-align:center; margin:20px 0;">
                            <a href="{{ rtrim(config('app.frontend_url'), '/') }}/dashboard/settings?tab=billing"
                               style="display:inline-block; padding:10px 18px; border-radius:999px; background:#16a34a; color:#ffffff; font-size:13px; font-weight:600; text-decoration:none;">
                                View plan &amp; upgrade options
                            </a>
                        </p>
                        <p style="font-size:12px; color:#a1a1aa; line-height:1.6; margin:16px 0 0;">
                            This email is sent when a limit is reached. Usage cards inside EsperWorks show live progress so you can act earlier next time.
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

