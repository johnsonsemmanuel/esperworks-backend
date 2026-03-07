<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'is_default',
        'layout_config',
        'custom_fields',
        'color_scheme',
        'font_settings',
        'header_settings',
        'footer_settings',
        'item_settings',
        'total_settings',
        'notes_settings',
        'payment_settings',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'layout_config' => 'array',
            'custom_fields' => 'array',
            'color_scheme' => 'array',
            'font_settings' => 'array',
            'header_settings' => 'array',
            'footer_settings' => 'array',
            'item_settings' => 'array',
            'total_settings' => 'array',
            'notes_settings' => 'array',
            'payment_settings' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    // Helper methods for template configuration
    public function getLayoutConfig(): array
    {
        return $this->layout_config ?? $this->getDefaultLayoutConfig();
    }

    public function getColorScheme(): array
    {
        return $this->color_scheme ?? $this->getDefaultColorScheme();
    }

    public function getCustomFields(): array
    {
        return $this->custom_fields ?? [];
    }

    private function getDefaultLayoutConfig(): array
    {
        return [
            'logo_position' => 'left',
            'business_info_position' => 'left',
            'client_info_position' => 'right',
            'invoice_details_position' => 'right',
            'items_table_width' => 'full',
            'totals_position' => 'right',
            'notes_position' => 'bottom',
            'payment_info_position' => 'bottom',
        ];
    }

    private function getDefaultColorScheme(): array
    {
        return [
            'primary_color' => '#00983a',
            'secondary_color' => '#0891b2',
            'text_color' => '#1e293b',
            'border_color' => '#e5e7eb',
            'background_color' => '#ffffff',
            'header_background' => '#f8fafc',
            'accent_color' => '#00983a',
        ];
    }

    public function getFontSettings(): array
    {
        return $this->font_settings ?? [
            'header_font' => 'Inter',
            'body_font' => 'Inter',
            'header_size' => '24',
            'body_size' => '12',
            'title_size' => '18',
            'subtitle_size' => '14',
        ];
    }

    public function getHeaderSettings(): array
    {
        return $this->header_settings ?? [
            'show_logo' => true,
            'show_business_name' => true,
            'show_business_address' => true,
            'show_business_phone' => true,
            'show_business_email' => true,
            'show_business_website' => false,
            'show_tax_id' => true,
            'custom_header_text' => '',
        ];
    }

    public function getFooterSettings(): array
    {
        return $this->footer_settings ?? [
            'show_payment_terms' => true,
            'show_bank_details' => true,
            'show_thank_you_note' => true,
            'custom_footer_text' => '',
            'show_page_numbers' => true,
        ];
    }

    public function getItemSettings(): array
    {
        return $this->item_settings ?? [
            'show_item_numbers' => true,
            'show_descriptions' => true,
            'show_quantities' => true,
            'show_rates' => true,
            'show_amounts' => true,
            'show_vat' => true,
            'item_name_label' => 'Item/Service',
            'quantity_label' => 'Qty',
            'rate_label' => 'Rate',
            'amount_label' => 'Amount',
        ];
    }

    public function getTotalSettings(): array
    {
        return $this->total_settings ?? [
            'show_subtotal' => true,
            'show_vat' => true,
            'show_discount' => false,
            'show_total' => true,
            'show_paid' => true,
            'show_balance' => true,
            'subtotal_label' => 'Subtotal',
            'vat_label' => 'VAT',
            'discount_label' => 'Discount',
            'total_label' => 'Total',
            'paid_label' => 'Paid',
            'balance_label' => 'Balance Due',
        ];
    }
}
