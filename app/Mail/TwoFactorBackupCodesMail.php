<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TwoFactorBackupCodesMail extends Mailable
{
    use Queueable, SerializesModels;

    public $codes;
    public $userName;

    public function __construct(array $codes, string $userName)
    {
        $this->codes = $codes;
        $this->userName = $userName;
    }

    public function build()
    {
        return $this
            ->subject('EsperWorks - Your Two-Factor Backup Codes')
            ->view('emails.two-factor-backup-codes')
            ->with([
                'codes' => $this->codes,
                'userName' => $this->userName
            ]);
    }
}
