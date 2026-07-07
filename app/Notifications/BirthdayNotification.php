<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BirthdayNotification extends Notification
{
    use Queueable;

    public Employee $employee;

    public function __construct(Employee $employee)
    {
        $this->employee = $employee;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'employee_id' => $this->employee->id,
            'employee_code' => $this->employee->employee_code,
            'employee_name' => $this->employee->full_name,
            'profile_image' => $this->employee->profile_image,
            'message' => "Today is {$this->employee->full_name}'s birthday! 🎂",
            'type' => 'birthday',
        ];
    }
}
