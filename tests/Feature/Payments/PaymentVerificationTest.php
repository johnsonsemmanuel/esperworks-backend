<?php

namespace Tests\Feature\Payments;

use App\Contracts\PaymentGateway;
use App\Events\PaymentReceived;
use App\Models\Business;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PaymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_verification_updates_payment_and_invoice_and_emits_event(): void
    {
        Event::fake([PaymentReceived::class]);

        $user = User::factory()->create([
            'role' => 'business_owner',
            'status' => 'active',
        ]);

        $business = Business::factory()->create([
            'user_id' => $user->id,
        ]);

        $client = Client::factory()->create([
            'business_id' => $business->id,
        ]);

        $invoice = Invoice::factory()->create([
            'business_id' => $business->id,
            'client_id' => $client->id,
            'total' => 1000,
            'amount_paid' => 0,
            'status' => 'sent',
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'client_id' => $client->id,
            'amount' => 1000,
            'currency' => 'GHS',
            'reference' => 'TEST-REF-123',
            'paystack_reference' => 'TEST-REF-123',
            'status' => 'pending',
        ]);

        $this->app->bind(PaymentGateway::class, function () {
            return new class implements PaymentGateway {
                public function initializeTransaction(array $data): array
                {
                    return ['status' => true, 'data' => []];
                }

                public function verifyTransaction(string $reference): array
                {
                    return [
                        'data' => [
                            'status' => 'success',
                            'channel' => 'card',
                        ],
                    ];
                }
            };
        });

        $response = $this->postJson('/api/payments/verify', [
            'reference' => 'TEST-REF-123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Payment verified successfully']);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'status' => 'paid',
            'amount_paid' => 1000,
        ]);

        Event::assertDispatched(PaymentReceived::class);
    }
}

