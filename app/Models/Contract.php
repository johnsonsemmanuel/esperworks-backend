<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use HasFactory;

    protected $hidden = ['signing_token'];

    protected $fillable = [
        'business_id', 'client_id', 'contract_number', 'title', 'type', 'industry_type',
        'content', 'status', 'value', 'pricing_type', 'created_date', 'expiry_date',
        'scope_of_work', 'milestones', 'payment_terms', 'ownership_rights',
        'confidentiality_enabled', 'termination_notice_days', 'termination_payment_note',
        'introduction_message', 'problem_solution', 'packages', 'add_ons', 'terms_lightweight',
        'business_signature_name', 'business_signature_image', 'business_signed_at',
        'client_signature_name', 'client_signature_image', 'client_signed_at',
        'pdf_path', 'sent_at', 'viewed_at', 'signing_token', 'token_expires_at',
        'client_response', 'client_response_at', 'client_response_ip', 'client_response_user_agent',
    ];

    protected function casts(): array
    {
        return [
            'created_date' => 'date',
            'expiry_date' => 'date',
            'value' => 'decimal:2',
            'confidentiality_enabled' => 'boolean',
            'scope_of_work' => 'array',
            'milestones' => 'array',
            'payment_terms' => 'array',
            'ownership_rights' => 'array',
            'problem_solution' => 'array',
            'packages' => 'array',
            'add_ons' => 'array',
            'business_signed_at' => 'datetime',
            'client_signed_at' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'client_response_at' => 'datetime',
            'client_response' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast() && $this->status !== 'signed';
    }

    public function canClientRespond(): bool
    {
        return in_array($this->status, ['sent', 'viewed']) && !$this->client_signed_at;
    }

    public function getClientResponse(): ?array
    {
        return $this->client_response;
    }

    public function hasClientAccepted(): bool
    {
        return is_array($this->client_response) && ($this->client_response['status'] ?? null) === 'accepted';
    }

    public function hasClientRejected(): bool
    {
        return is_array($this->client_response) && ($this->client_response['status'] ?? null) === 'rejected';
    }

    public function hasClientResponded(): bool
    {
        return !empty($this->client_response);
    }

    /** Record that the client accepted (before signing). Does not set signature or signed_at. */
    public function recordAccept(): bool
    {
        $newStatus = $this->isFullySigned() ? 'signed' : 'accepted';
        $this->update([
            'client_response' => ['status' => 'accepted'],
            'client_response_at' => now(),
            'client_response_ip' => request()->ip(),
            'client_response_user_agent' => request()->userAgent(),
            'status' => $newStatus,
        ]);
        $this->fireClientResponseEvent('accepted');
        return true;
    }

    /** Accept and sign in one step (legacy). Prefer recordAccept() + sign for accept-before-sign flow. */
    public function acceptContract(string $signatureName, string $signatureImage): bool
    {
        $this->update([
            'client_response' => ['status' => 'accepted', 'signature_name' => $signatureName, 'signature_image' => $signatureImage],
            'client_response_at' => now(),
            'client_response_ip' => request()->ip(),
            'client_response_user_agent' => request()->userAgent(),
            'client_signed_at' => now(),
            'status' => 'signed',
        ]);
        $this->fireClientResponseEvent('accepted');
        return true;
    }

    public function rejectContract(string $reason): bool
    {
        $this->update([
            'client_response' => ['status' => 'rejected', 'reason' => $reason],
            'client_response_at' => now(),
            'client_response_ip' => request()->ip(),
            'client_response_user_agent' => request()->userAgent(),
        ]);

        $this->fireClientResponseEvent('rejected');
        return true;
    }

    private function fireClientResponseEvent(string $action): void
    {
        // Fire event for ML recommendations and notifications
        event(new \App\Events\ClientContractResponse($this->id, $this->client_id, $this->business_id, $action));
    }

    public function isFullySigned(): bool
    {
        return $this->business_signed_at && $this->client_signed_at;
    }

    public function hasLinkedInvoice(): bool
    {
        return $this->invoices()->exists();
    }

    public function getLinkedInvoice(): ?Invoice
    {
        return $this->invoices()->first();
    }
}
