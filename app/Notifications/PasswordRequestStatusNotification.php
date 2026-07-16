<?php

namespace App\Notifications;

use App\Models\PasswordChangeRequest;
use Illuminate\Notifications\Notification;

class PasswordRequestStatusNotification extends Notification
{
    public $changeRequest;
    public $status;

    public function __construct(PasswordChangeRequest $changeRequest, string $status)
    {
        $this->changeRequest = $changeRequest;
        $this->status = $status;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $employee = $this->changeRequest->employee;

        return [
            'password_change_request_id' => $this->changeRequest->id,
            'employee_id' => $employee?->id,
            'employee_name' => $employee?->full_name ?? $notifiable->name,
            'employee_code' => $employee?->employee_code ?? '',
            'profile_image' => $employee?->profile_image ?? null,
            'type' => $this->status === 'approved' ? 'password_request_approved' : 'password_request_rejected',
            'message' => $this->status === 'approved'
                ? 'Your password change request has been approved. Click to enter OTP and set a new password.'
                : 'Your password change request has been rejected. Please contact administration for more information.',
        ];
    }
}
