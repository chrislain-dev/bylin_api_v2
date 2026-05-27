<?php

declare(strict_types=1);

namespace Modules\Notification\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Modules\Notification\Models\Notification;

class SendEmailNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public Notification $notification,
    ) {}

    public function handle(): void
    {
        $notifiable = $this->notification->notifiable;
        $email = $notifiable?->email ?? null;

        if (! $email) {
            $this->notification->markAsFailed('Notifiable email address is missing.');
            return;
        }

        Mail::raw($this->notification->message, function ($message) use ($email) {
            $message->to($email)
                ->subject($this->notification->title);
        });

        $this->notification->markAsSent();
    }

    public function failed(\Throwable $exception): void
    {
        $this->notification->markAsFailed($exception->getMessage());
    }
}
