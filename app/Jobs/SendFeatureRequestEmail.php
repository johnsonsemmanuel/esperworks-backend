<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Models\Setting;

class SendFeatureRequestEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $userName,
        public string $userEmail,
        public string $title,
        public ?string $priority,
        public string $description
    ) {}

    public function handle(): void
    {
        try {
            Mail::raw(
                "New Feature Request from {$this->userName} ({$this->userEmail})\n\n" .
                "Title: {$this->title}\n" .
                "Priority: " . ($this->priority ?? 'none') . "\n" .
                "Description: {$this->description}\n",
                function ($mail) {
                    $mail->to(Setting::get('support_email', config('mail.from.address', 'support@esperworks.com')))
                         ->subject('EsperWorks Feature Request');
                }
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
