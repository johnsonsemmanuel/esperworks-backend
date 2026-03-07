<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TwoFactorCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $code;
    public $userName;

    public function __construct(string $code, string $userName)
    {
        $this->code = $code;
        $this->userName = $userName;
    }

    public function build()
    {
        return $this
            ->subject('EsperWorks - Your Two-Factor Authentication Code')
            ->view('emails.two-factor-code')
            ->with([
                'code' => $this->code,
                'userName' => $this->userName,
                'expiryMinutes' => 10
            ]);
    }
}
