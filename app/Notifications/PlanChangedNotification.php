<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class PlanChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private function displayPlan(string $plan): string
    {
        return \App\Models\Business::planDisplayName($plan);
    }

    public function __construct(
        private string $oldPlan,
        private string $newPlan
    ) {}

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return (new \Illuminate\Mail\Mailable)
            ->subject('Your EsperWorks plan has been updated')
            ->markdown('emails.plan-changed', [
                'oldPlan' => $this->displayPlan($this->oldPlan),
                'newPlan' => $this->displayPlan($this->newPlan),
                'userName' => $notifiable->name,
            ]);
    }

    public function toArray($notifiable)
    {
        return [
            'title' => 'Plan updated',
            'message' => "Your plan has been updated from {$this->displayPlan($this->oldPlan)} to {$this->displayPlan($this->newPlan)}.",
            'type' => 'plan_changed',
            'data' => [
                'old_plan' => $this->oldPlan,
                'new_plan' => $this->newPlan,
            ],
        ];
    }
}
