<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class CurrencyPrecisionService
{
    /**
     * Standard decimal precision for financial calculations
     */
    const STANDARD_PRECISION = 2;
    
    /**
     * Supported currencies and their precision requirements
     */
    const CURRENCY_PRECISION = [
        'GHS' => 2,  // Ghana Cedis - 2 decimal places
        'USD' => 2,  // US Dollar - 2 decimal places
        'EUR' => 2,  // Euro - 2 decimal places
        'GBP' => 2,  // British Pound - 2 decimal places
        'NGN' => 2,  // Nigerian Naira - 2 decimal places
        'JPY' => 0,  // Japanese Yen - no decimal places
        'KES' => 2,  // Kenyan Shilling - 2 decimal places
    ];
    
    /**
     * Get precision for a currency
     */
    public static function getPrecision(string $currencyCode): int
    {
        return self::CURRENCY_PRECISION[strtoupper($currencyCode)] ?? self::STANDARD_PRECISION;
    }
    
    /**
     * Round amount to currency precision
     */
    public static function round(float $amount, string $currencyCode = 'GHS'): float
    {
        $precision = self::getPrecision($currencyCode);
        $multiplier = pow(10, $precision);
        return round($amount * $multiplier) / $multiplier;
    }
    
    /**
     * Format amount for display with proper precision
     */
    public static function format(float $amount, string $currencyCode = 'GHS'): string
    {
        $precision = self::getPrecision($currencyCode);
        $roundedAmount = self::round($amount, $currencyCode);
        
        return number_format($roundedAmount, $precision, '.', '');
    }
    
    /**
     * Validate amount precision for a currency
     */
    public static function validatePrecision(float $amount, string $currencyCode = 'GHS'): array
    {
        $precision = self::getPrecision($currencyCode);
        $roundedAmount = self::round($amount, $currencyCode);
        
        $difference = abs($amount - $roundedAmount);
        $maxDifference = pow(0.1, $precision) / 2; // Half of the smallest unit
        
        return [
            'is_valid' => $difference <= $maxDifference,
            'rounded_amount' => $roundedAmount,
            'difference' => $difference,
            'precision' => $precision,
            'max_allowed_difference' => $maxDifference,
        ];
    }
    
    /**
     * Calculate difference between two amounts with proper precision
     */
    public static function calculateDifference(float $amount1, float $amount2, string $currencyCode = 'GHS'): float
    {
        return self::round($amount1 - $amount2, $currencyCode);
    }
    
    /**
     * Add two amounts with proper precision
     */
    public static function add(float $amount1, float $amount2, string $currencyCode = 'GHS'): float
    {
        return self::round($amount1 + $amount2, $currencyCode);
    }
    
    /**
     * Subtract two amounts with proper precision
     */
    public static function subtract(float $amount1, float $amount2, string $currencyCode = 'GHS'): float
    {
        return self::round($amount1 - $amount2, $currencyCode);
    }
    
    /**
     * Multiply amounts with proper precision
     */
    public static function multiply(float $amount1, float $amount2, string $currencyCode = 'GHS'): float
    {
        return self::round($amount1 * $amount2, $currencyCode);
    }
    
    /**
     * Divide amounts with proper precision
     */
    public static function divide(float $amount1, float $amount2, string $currencyCode = 'GHS'): float
    {
        if ($amount2 == 0) {
            Log::error('Division by zero attempted in currency calculation', [
                'amount1' => $amount1,
                'amount2' => $amount2,
                'currency' => $currencyCode
            ]);
            return 0;
        }
        
        return self::round($amount1 / $amount2, $currencyCode);
    }
    
    /**
     * Calculate percentage with proper precision
     */
    public static function percentage(float $amount, float $total, string $currencyCode = 'GHS'): float
    {
        if ($total == 0) {
            return 0;
        }
        
        return self::round(($amount / $total) * 100, $currencyCode);
    }
    
    /**
     * Apply tax calculation with proper precision
     */
    public static function calculateTax(float $amount, float $taxRate, string $currencyCode = 'GHS'): array
    {
        $taxAmount = self::multiply($amount, $taxRate / 100, $currencyCode);
        $totalWithTax = self::add($amount, $taxAmount, $currencyCode);
        
        return [
            'original_amount' => $amount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total_with_tax' => $totalWithTax,
            'currency' => $currencyCode,
        ];
    }
    
    /**
     * Validate financial calculation for accounting accuracy
     */
    public static function validateCalculation(
        array $calculation, 
        string $type = 'general',
        string $currencyCode = 'GHS'
    ): array {
        $errors = [];
        $warnings = [];
        
        switch ($type) {
            case 'payment_reconciliation':
                if (isset($calculation['invoice_total'])) {
                    $validation = self::validatePrecision($calculation['invoice_total'], $currencyCode);
                    if (!$validation['is_valid']) {
                        $errors[] = "Invoice total precision error: difference of {$validation['difference']} exceeds maximum allowed {$validation['max_allowed_difference']}";
                    }
                }
                
                if (isset($calculation['amount_paid'])) {
                    $validation = self::validatePrecision($calculation['amount_paid'], $currencyCode);
                    if (!$validation['is_valid']) {
                        $errors[] = "Amount paid precision error: difference of {$validation['difference']} exceeds maximum allowed {$validation['max_allowed_difference']}";
                    }
                }
                
                if (isset($calculation['payment_amount'])) {
                    $validation = self::validatePrecision($calculation['payment_amount'], $currencyCode);
                    if (!$validation['is_valid']) {
                        $errors[] = "Payment amount precision error: difference of {$validation['difference']} exceeds maximum allowed {$validation['max_allowed_difference']}";
                    }
                }
                
                // Check if payment amount matches expected amount
                if (isset($calculation['payment_amount']) && isset($calculation['expected_amount'])) {
                    $difference = abs($calculation['payment_amount'] - $calculation['expected_amount']);
                    $maxDifference = pow(0.1, self::getPrecision($currencyCode)) / 2;
                    if ($difference > $maxDifference) {
                        $warnings[] = "Payment amount differs from expected amount by {$difference}";
                    }
                }
                break;
                
            case 'expense_calculation':
                if (isset($calculation['amount'])) {
                    $validation = self::validatePrecision($calculation['amount'], $currencyCode);
                    if (!$validation['is_valid']) {
                        $errors[] = "Expense amount precision error: difference of {$validation['difference']} exceeds maximum allowed {$validation['max_allowed_difference']}";
                    }
                }
                break;
                
            case 'profit_loss':
                if (isset($calculation['income'])) {
                    $validation = self::validatePrecision($calculation['income'], $currencyCode);
                    if (!$validation['is_valid']) {
                        $errors[] = "Income precision error: difference of {$validation['difference']} exceeds maximum allowed {$validation['max_allowed_difference']}";
                    }
                }
                
                if (isset($calculation['expenses'])) {
                    $validation = self::validatePrecision($calculation['expenses'], $currencyCode);
                    if (!$validation['is_valid']) {
                        $errors[] = "Expenses precision error: difference of {$validation['difference']} exceeds maximum allowed {$validation['max_allowed_difference']}";
                    }
                }
                
                if (isset($calculation['profit'])) {
                    $validation = self::validatePrecision($calculation['profit'], $currencyCode);
                    if (!$validation['is_valid']) {
                        $errors[] = "Profit precision error: difference of {$validation['difference']} exceeds maximum allowed {$validation['max_allowed_difference']}";
                    }
                }
                break;
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'currency_code' => $currencyCode,
            'precision' => self::getPrecision($currencyCode),
        ];
    }
}
