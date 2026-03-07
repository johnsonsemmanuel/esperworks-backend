<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $businessName;
    public $role;
    public $inviteUrl;

    public function __construct(string $userName, string $businessName, string $role, string $inviteUrl)
    {
        $this->userName = $userName;
        $this->businessName = $businessName;
        $this->role = $role;
        $this->inviteUrl = $inviteUrl;
    }

    public function build()
    {
        return $this
            ->subject("You're invited to join {$this->businessName} on EsperWorks")
            ->view('emails.team-invitation')
            ->with([
                'userName' => $this->userName,
                'businessName' => $this->businessName,
                'role' => ucfirst($this->role),
                'inviteUrl' => $this->inviteUrl,
                'expiryDays' => 7
            ]);
    }
}
