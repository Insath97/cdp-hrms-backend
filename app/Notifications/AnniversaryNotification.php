<?php

namespace App\Notifications;

use App\Models\Employee;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class AnniversaryNotification extends Notification
{
    use Queueable;

    public Employee $employee;

    public int $years;

    public function __construct(Employee $employee, int $years)
    {
        $this->employee = $employee;
        $this->years = $years;
    }

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        $label = $this->years === 1 ? 'year' : 'years';

        return [
            'employee_id' => $this->employee->id,
            'employee_code' => $this->employee->employee_code,
            'employee_name' => $this->employee->full_name,
            'profile_image' => $this->employee->profile_image,
            'years' => $this->years,
            'message' => "Happy {$this->years} {$label} anniversary with CDP! 🎉",
            'type' => 'anniversary',
        ];
    }
}
