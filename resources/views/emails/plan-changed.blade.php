@component('mail::message')
# Your plan is now active

Hello {{ $userName }},

Your EsperWorks workspace has moved from **{{ $oldPlan }}** to **{{ $newPlan }}**.

## What this means for your business

Your new plan is ready immediately. You can now:

- Continue operating with your updated limits and capabilities
- Keep workflows streamlined across invoicing, contracts, and clients
- Review plan details anytime in Billing settings

## Need a hand?

If you have questions about your plan, our team is ready to help.

Thank you for choosing EsperWorks!

Best regards,  
The EsperWorks Team
@endcomponent
