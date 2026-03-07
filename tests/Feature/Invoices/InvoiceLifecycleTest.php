<?php

namespace Tests\Feature\Invoices;

use App\Models\Business;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_create_and_send_invoice(): void
    {
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

        $token = $user->createToken('auth-token')->plainTextToken;

        $payload = [
            'client_id' => $client->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'currency' => 'GHS',
            'vat_rate' => 0,
            'notes' => 'Test invoice',
            'items' => [
                [
                    'description' => 'Service',
                    'quantity' => 1,
                    'rate' => 1000,
                ],
            ],
        ];

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/businesses/{$business->id}/invoices", $payload);

        $response->assertStatus(201)
            ->assertJsonPath('invoice.client_id', $client->id);

        $invoiceId = $response->json('invoice.id');

        $sendResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/businesses/{$business->id}/invoices/{$invoiceId}/send");

        $sendResponse->assertStatus(200)
            ->assertJson(['message' => 'Invoice sent successfully']);
    }
}

