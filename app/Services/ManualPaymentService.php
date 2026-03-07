<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Invoice;
use App\Models\Payment;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManualPaymentService
{
    /**
     * Record a manual payment for a business. Optionally link to an invoice.
     * When linked to an invoice: updates amount_paid, status (paid/partial), sends receipt if fully paid, dispatches event, logs activity.
     *
     * @param  array{ business_id: int, invoice_id?: int, amount: float, method: string, reference?: string, paid_at?: string, currency?: string }  $data
     * @return Payment
     */
    public function record(array $data): Payment
    {
        $business = Business::findOrFail($data['business_id']);
        $invoice = null;
        $clientId = $data['client_id'] ?? null;
        $currency = $data['currency'] ?? 'GHS';

        if (!empty($data['invoice_id'])) {
            $invoice = Invoice::where('id', $data['invoice_id'])
                ->where('business_id', $business->id)
                ->firstOrFail();
            $clientId = $invoice->client_id;
            $currency = $invoice->currency;
        }

        $amount = (float) $data['amount'];
        $method = $data['method'] ?? 'cash';
        $reference = $data['reference'] ?? 'MANUAL-' . Str::upper(Str::random(8));
        $paidAt = isset($data['paid_at']) ? \Carbon\Carbon::parse($data['paid_at']) : now();

        if ($invoice) {
            $balanceDue = $invoice->total - $invoice->amount_paid;
            if ($balanceDue <= 0) {
                throw new \InvalidArgumentException('Invoice is already fully paid.');
            }
            $amount = min($amount, $balanceDue);
        }

        $payment = DB::transaction(function () use ($business, $invoice, $clientId, $amount, $currency, $method, $reference, $paidAt) {
            $payment = Payment::create([
                'business_id' => $business->id,
                'invoice_id' => $invoice?->id,
                'client_id' => $clientId,
                'amount' => $amount,
                'currency' => $currency,
                'method' => $method,
                'reference' => $reference,
                'status' => 'success',
                'paid_at' => $paidAt,
            ]);

            if ($invoice) {
                $totalPaid = $invoice->payments()->where('status', 'success')->sum('amount');
                $invoice->update(['amount_paid' => $totalPaid]);

                if ($invoice->isFullyPaid()) {
                    $invoice->markAsPaid();
                } else {
                    $invoice->update(['status' => 'partially_paid']);
                }
            }

            return $payment;
        });

        // Non-transactional: receipt email and event
        if ($invoice && $invoice->isFullyPaid()) {
            $invoice->load(['business', 'client']);
            try {
                \Illuminate\Support\Facades\Mail::to($invoice->client->email)->send(new InvoiceMail($invoice, 'receipt'));
            } catch (\Exception $e) {
                \Log::warning('ManualPaymentService: Failed to send receipt email: ' . $e->getMessage());
            }
        }

        try {
            \App\Events\PaymentReceived::dispatch(
                $payment->id,
                $payment->client_id,
                $payment->business_id,
                (float) $payment->amount,
                $payment->reference
            );
        } catch (\Exception $e) {
            \Log::warning('ManualPaymentService: Failed to dispatch PaymentReceived: ' . $e->getMessage());
        }

        $invoiceForLog = $payment->invoice;
        $logMessage = $invoiceForLog
            ? "Manual payment of GH₵ {$payment->amount} recorded for invoice {$invoiceForLog->invoice_number}"
            : "Manual payment of GH₵ {$payment->amount} recorded";
        ActivityLog::log('payment.manual', $logMessage, $payment->invoice ?? $payment->business);

        return $payment;
    }
}
