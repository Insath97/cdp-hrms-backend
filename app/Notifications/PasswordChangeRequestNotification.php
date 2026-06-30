<?php

namespace App\Notifications;

use App\Models\PasswordChangeRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangeRequestNotification extends Notification implements ShouldQueue
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
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $user = $this->changeRequest->user;
        $employee = $this->changeRequest->employee;
        $requesterName = $user ? $user->name : ($employee ? $employee->full_name : 'Unknown User');
        $requesterEmail = $user ? $user->email : 'N/A';
        $typeLabel = $this->requestType === 'forgot_password' ? 'Forgot Password' : 'Password Change';

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173/');
        // Link to the admin panel where password requests can be reviewed
        $reviewUrl = rtrim($frontendUrl, '/') . '/admin/password-requests'; 

        return (new MailMessage)
            ->subject("Pending {$typeLabel} Request - " . config('app.name'))
            ->greeting("Hello Admin,")
            ->line("A new {$typeLabel} request has been received and requires your review and approval.")
            ->line("Requester Details:")
            ->line("• Name: {$requesterName}")
            ->line("• Email: {$requesterEmail}")
            ->line("• Submitted At: " . $this->changeRequest->created_at->toDayDateTimeString())
            ->action('Review Request', $reviewUrl)
            ->line('Once reviewed, you can approve or reject the request through the administration portal.')
            ->salutation("Regards,\n" . config('app.name') . " Team");
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        $user = $this->changeRequest->user;
        $employee = $this->changeRequest->employee;
        return [
            'password_change_request_id' => $this->changeRequest->id,
            'user_id' => $this->changeRequest->user_id,
            'employee_id' => $this->changeRequest->employee_id,
            'requester_name' => $user ? $user->name : ($employee ? $employee->full_name : 'Unknown User'),
            'request_type' => $this->requestType,
            'message' => "A new " . ($this->requestType === 'forgot_password' ? 'forgot password' : 'password change') . " request is pending approval.",
        ];
    }
}
