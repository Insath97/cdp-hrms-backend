<?php

namespace App\Notifications;

use App\Models\PasswordChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordRequestUserNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public $changeRequest;
    public $requestType; // 'forgot_password' or 'password_change'

    /**
     * Create a new notification instance.
     */
    public function __construct(PasswordChangeRequest $changeRequest, string $requestType)
    {
        $this->changeRequest = $changeRequest;
        $this->requestType = $requestType;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $typeLabel = $this->requestType === 'forgot_password' ? 'Forgot Password' : 'Password Change';

        return (new MailMessage)
            ->subject("Your {$typeLabel} Request Received - " . config('app.name'))
            ->greeting("Hello " . $notifiable->name . ",")
            ->line("We have received your request to reset or change your password.")
            ->line("Request Status: Pending Admin Approval")
            ->line("What happens next?")
            ->line("1. An administrator will review your request.")
            ->line("2. Once approved, you will receive a 6-digit OTP (One Time Password) via SMS on your registered primary phone number.")
            ->line("3. You will use that OTP to complete setting your new password.")
            ->line("If you did not make this request, please contact the administration or HR department immediately to secure your account.")
            ->salutation("Regards,\n" . config('app.name') . " Team");
    }
}
