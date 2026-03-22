<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvoiceTemplate;
use App\Models\Business;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceTemplateController extends Controller
{
    public function index(Request $request, Business $business)
    {
        $templates = $business->invoiceTemplates()
            ->withCount('invoices')
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json(['templates' => $templates]);
    }

    public function store(Request $request, Business $business)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'layout_config' => 'nullable|array',
            'custom_fields' => 'nullable|array',
            'color_scheme' => 'nullable|array',
            'font_settings' => 'nullable|array',
            'header_settings' => 'nullable|array',
            'footer_settings' => 'nullable|array',
            'item_settings' => 'nullable|array',
            'total_settings' => 'nullable|array',
            'notes_settings' => 'nullable|array',
            'payment_settings' => 'nullable|array',
        ]);

        // Check plan restrictions
        if (!$business->canCreateInvoiceTemplate()) {
            $plan = $business->plan ?? 'free';
            return response()->json([
                'message' => "You're operating at full capacity. Upgrade to keep workflows uninterrupted.",
                'plan' => $plan,
                'plan_name' => \App\Models\Business::planDisplayName($plan),
                'upgrade_required' => true,
            ], 403);
        }

        // If this is set as default, unset other defaults
        if ($request->is_default) {
            $business->invoiceTemplates()->where('is_default', true)->update(['is_default' => false]);
        }

        $template = InvoiceTemplate::create([
            'business_id' => $business->id,
            'name' => $request->name,
            'description' => $request->description,
            'is_default' => $request->is_default ?? false,
            'layout_config' => $request->layout_config,
            'custom_fields' => $request->custom_fields,
            'color_scheme' => $request->color_scheme,
            'font_settings' => $request->font_settings,
            'header_settings' => $request->header_settings,
            'footer_settings' => $request->footer_settings,
            'item_settings' => $request->item_settings,
            'total_settings' => $request->total_settings,
            'notes_settings' => $request->notes_settings,
            'payment_settings' => $request->payment_settings,
        ]);

        ActivityLog::log('invoice_template.created', "Invoice template created: {$template->name}", $template);

        return response()->json(['message' => 'Template created', 'template' => $template], 201);
    }

    public function show(Business $business, InvoiceTemplate $template)
    {
        if ($template->business_id !== $business->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        return response()->json(['template' => $template]);
    }

    public function update(Request $request, Business $business, InvoiceTemplate $template)
    {
        if ($template->business_id !== $business->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'layout_config' => 'nullable|array',
            'custom_fields' => 'nullable|array',
            'color_scheme' => 'nullable|array',
            'font_settings' => 'nullable|array',
            'header_settings' => 'nullable|array',
            'footer_settings' => 'nullable|array',
            'item_settings' => 'nullable|array',
            'total_settings' => 'nullable|array',
            'notes_settings' => 'nullable|array',
            'payment_settings' => 'nullable|array',
            'is_default' => 'boolean',
        ]);

        // If this is set as default, unset other defaults
        if ($request->is_default) {
            $business->invoiceTemplates()->where('id', '!=', $template->id)->where('is_default', true)->update(['is_default' => false]);
        }

        $template->update($request->only([
            'name', 'description', 'is_default', 'layout_config', 'custom_fields',
            'color_scheme', 'font_settings', 'header_settings', 'footer_settings',
            'item_settings', 'total_settings', 'notes_settings', 'payment_settings',
        ]));

        ActivityLog::log('invoice_template.updated', "Invoice template updated: {$template->name}", $template);

        return response()->json(['message' => 'Template updated', 'template' => $template]);
    }

    public function destroy(Business $business, InvoiceTemplate $template)
    {
        if ($template->business_id !== $business->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        // Check if template is being used by invoices
        if ($template->invoices()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete template that is being used by invoices',
                'invoices_count' => $template->invoices()->count(),
            ], 422);
        }

        // If this was the default, set another template as default
        if ($template->is_default) {
            $otherTemplate = $business->invoiceTemplates()->where('id', '!=', $template->id)->first();
            if ($otherTemplate) {
                $otherTemplate->update(['is_default' => true]);
            }
        }

        $template->delete();

        ActivityLog::log('invoice_template.deleted', "Invoice template deleted: {$template->name}", $template);

        return response()->json(['message' => 'Template deleted']);
    }

    public function setDefault(Business $business, InvoiceTemplate $template)
    {
        if ($template->business_id !== $business->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        // Unset all other defaults
        $business->invoiceTemplates()->where('id', '!=', $template->id)->update(['is_default' => false]);

        // Set this as default
        $template->update(['is_default' => true]);

        ActivityLog::log('invoice_template.default_set', "Template set as default: {$template->name}", $template);

        return response()->json(['message' => 'Template set as default']);
    }

    public function duplicate(Business $business, InvoiceTemplate $template)
    {
        if ($template->business_id !== $business->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        // Check plan restrictions
        if (!$business->canCreateInvoiceTemplate()) {
            $plan = $business->plan ?? 'free';
            return response()->json([
                'message' => "You're operating at full capacity. Upgrade to keep workflows uninterrupted.",
                'plan' => $plan,
                'plan_name' => \App\Models\Business::planDisplayName($plan),
                'upgrade_required' => true,
            ], 403);
        }

        $newTemplate = $template->replicate();
        $newTemplate->name = $template->name . ' (Copy)';
        $newTemplate->is_default = false;
        $newTemplate->save();

        ActivityLog::log('invoice_template.duplicated', "Template duplicated: {$newTemplate->name}", $newTemplate);

        return response()->json(['message' => 'Template duplicated', 'template' => $newTemplate], 201);
    }

    public function preview(Business $business, InvoiceTemplate $template)
    {
        if ($template->business_id !== $business->id) {
            return response()->json(['message' => 'Template not found'], 404);
        }

        // Create a sample invoice data for preview
        $sampleInvoice = [
            'invoice_number' => 'INV-001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 1000.00,
            'vat_rate' => 15,
            'vat_amount' => 150.00,
            'total' => 1150.00,
            'amount_paid' => 0,
            'balance_due' => 1150.00,
            'notes' => 'Sample notes for preview',
            'business' => $business,
            'client' => [
                'name' => 'Sample Client',
                'address' => '123 Sample Street',
                'city' => 'Sample City',
                'country' => 'Ghana',
                'email' => 'client@example.com',
                'phone' => '+233 123 456 789',
            ],
            'items' => [
                [
                    'description' => 'Sample Service 1',
                    'quantity' => 1,
                    'rate' => 500.00,
                    'total' => 500.00,
                ],
                [
                    'description' => 'Sample Service 2',
                    'quantity' => 2,
                    'rate' => 250.00,
                    'total' => 500.00,
                ],
            ],
        ];

        // Generate preview HTML
        $html = view('pdf.invoice_template', [
            'invoice' => (object) $sampleInvoice,
            'template' => $template,
            'business' => $business,
            'client' => (object) $sampleInvoice['client'],
            'items' => $sampleInvoice['items'],
        ])->render();

        return response()->json(['preview_html' => $html]);
    }
}
