<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AttendanceSessionExpiryService
{
    /**
     * A session stays open this many minutes after office end time before it expires.
     */
    public const EXPIRE_AFTER_MINUTES = 60;

    /**
     * Close every open session whose expiry deadline has passed.
     * Covers stale sessions left open on previous days as well as
     * today's sessions once office end time + grace has elapsed.
     */
    public function expireStaleSessions(): int
    {
        $now = Carbon::now();
        $today = $now->toDateString();

        $query = Attendance::query()
            ->whereNull('clock_out')
            ->whereNull('session_expired_at')
            ->whereNotNull('clock_in');

        if ($now->lt($this->deadlineFor($today))) {
            // Today's deadline has not passed yet, only earlier days are stale
            $query->whereDate('date', '<', $today);
        } else {
            $query->whereDate('date', '<=', $today);
        }

        return $this->closeSessions($query->get());
    }

    /**
     * Close a single user's open sessions dated before the given date so that
     * clocking in for a new day always starts a fresh session.
     */
    public function expireSessionsBefore(int $userId, string $date): int
    {
        $sessions = Attendance::query()
            ->where('user_id', $userId)
            ->whereNull('clock_out')
            ->whereNull('session_expired_at')
            ->whereNotNull('clock_in')
            ->whereDate('date', '<', $date)
            ->get();

        return $this->closeSessions($sessions);
    }

    /**
     * Expiry deadline (office end time + grace) for a given date.
     */
    public function deadlineFor(string $date): Carbon
    {
        $officeEnd = AttendanceSetting::getSetting('office_end_time', '17:00:00');

        if (! $officeEnd) {
            $officeEnd = '17:00:00';
        }

        return Carbon::parse($date.' '.$officeEnd)->addMinutes(self::EXPIRE_AFTER_MINUTES);
    }

    /**
     * Mark a session as closed without fabricating a clock-out time. The
     * employee never clocked out, so clock_out and working_hours stay null;
     * the day is left as an incomplete record that can be resolved through an
     * attendance correction.
     *
     * @param  Collection<int, Attendance>  $sessions
     */
    private function closeSessions(Collection $sessions): int
    {
        $expired = 0;
        $now = Carbon::now();

        foreach ($sessions as $attendance) {
            if ($attendance->clock_in === null) {
                continue;
            }

            $attendance->session_expired_at = $now;
            $attendance->save();

            $expired++;

            Log::info('Attendance session expired', [
                'attendance_id' => $attendance->id,
                'user_id' => $attendance->user_id,
                'date' => $attendance->date instanceof Carbon
                    ? $attendance->date->toDateString()
                    : Carbon::parse($attendance->date)->toDateString(),
                'clock_in' => $this->toTime($attendance->clock_in),
                'session_expired_at' => $now->toDateTimeString(),
                'note' => 'Session closed without a clock-out time; awaiting attendance correction.',
            ]);
        }

        return $expired;
    }

    private function toTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->format('H:i:s');
        }

        return substr((string) $value, 0, 8);
    }
}
