<?php

namespace App\Listeners;

use App\Events\InvoiceSent;
use App\Events\PaymentReceived;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendClientNotification implements ShouldQueue
{
    public function handle(object $event): void
    {
        try {
            if ($event instanceof InvoiceSent) {
                // Send notification to client when invoice is sent
                $this->sendInvoiceNotification($event);
            } elseif ($event instanceof PaymentReceived) {
                // Send notification to client when payment is received
                $this->sendPaymentNotification($event);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send client notification: ' . $e->getMessage(), [
                'event_class' => get_class($event),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function sendInvoiceNotification(InvoiceSent $event): void
    {
        // Get client user ID from the invoice
        $invoice = \App\Models\Invoice::find($event->invoiceId);
        if (!$invoice || !$invoice->client) {
            return;
        }

        $clientUser = $invoice->client->user;
        if (!$clientUser) {
            return;
        }

        // Create notification for client
        NotificationController::create(
            $clientUser->id,
            'invoice_sent',
            'Invoice Received',
            "Invoice {$event->invoiceNumber} for GH₵{$event->total} has been sent to you.",
            $event->businessId,
            [
                'invoice_id' => $event->invoiceId,
                'invoice_number' => $event->invoiceNumber,
                'total' => $event->total,
                'client_id' => $event->clientId,
            ]
        );

        Log::info('Client notification sent for invoice', [
            'invoice_id' => $event->invoiceId,
            'invoice_number' => $event->invoiceNumber,
            'client_id' => $event->clientId,
            'client_user_id' => $clientUser->id,
        ]);
    }

    private function sendPaymentNotification(PaymentReceived $event): void
    {
        // Get payment details
        $payment = \App\Models\Payment::find($event->paymentId);
        if (!$payment || !$payment->client) {
            return;
        }

        $clientUser = $payment->client->user;
        if (!$clientUser) {
            return;
        }

        // Create notification for client
        NotificationController::create(
            $clientUser->id,
            'payment_received',
            'Payment Received',
            "Payment of GH₵{$event->amount} (Ref: {$event->reference}) has been received.",
            $event->businessId,
            [
                'payment_id' => $event->paymentId,
                'amount' => $event->amount,
                'reference' => $event->reference,
                'client_id' => $event->clientId,
            ]
        );

        Log::info('Client notification sent for payment', [
            'payment_id' => $event->paymentId,
            'amount' => $event->amount,
            'reference' => $event->reference,
            'client_id' => $event->clientId,
            'client_user_id' => $clientUser->id,
        ]);
    }
}
