<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contract;
use App\Models\Client;

class CreateTestContract extends Command
{
    protected $signature = 'contract:create-test';
    protected $description = 'Create a test contract for signing';

    public function handle()
    {
        // Get or create a test client
        $client = Client::first();
        if (!$client) {
            $this->error('No client found. Please create a client first.');
            return 1;
        }

        // Create test contract
        $contract = Contract::create([
            'business_id' => 1,
            'client_id' => $client->id,
            'title' => 'Test Contract for Signing',
            'type' => 'contract',
            'content' => 'This is a test contract created for testing public signature functionality.',
            'value' => 1000,
            'created_date' => now(),
            'expiry_date' => now()->addDays(30),
            'status' => 'sent',
            'signing_token' => 'test-sign-token-123',
        ]);

        $this->info("Test contract created successfully!");
        $this->info("Contract ID: {$contract->id}");
        $this->info("Token: {$contract->signing_token}");
        $this->info("Test URL: http://localhost:3002/contracts/view?token={$contract->signing_token}");

        return 0;
    }
}
