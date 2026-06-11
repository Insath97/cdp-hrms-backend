<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Employee;
use App\Models\User;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\Holiday;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MarkDailyAbsenteesTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveEmployeeAndUser(string $code, string $email, string $username)
    {
        $employee = Employee::create([
            'full_name' => 'John Doe ' . $code,
            'employee_code' => $code,
            'id_number' => 'NIC-' . $code,
            'date_of_birth' => '1990-01-01',
            'email' => $email,
            'phone_primary' => '1234567890',
            'employment_status' => 'active',
            'is_active' => true,
            'joined_at' => '2026-06-01',
        ]);

        $user = User::create([
            'employee_id' => $employee->id,
            'name' => 'John Doe ' . $code,
            'username' => $username,
            'email' => $email,
            'password' => bcrypt('password'),
            'is_active' => true,
            'can_login' => true,
        ]);

        return [$employee, $user];
    }

    public function test_active_employees_without_attendance_or_leave_are_marked_absent()
    {
        // Setup: Active employee and user (2026-06-09 is a Tuesday)
        [$employee, $user] = $this->createActiveEmployeeAndUser('EMP001', 'emp1@example.com', 'emp1');

        $testDate = '2026-06-09';

        // Run command
        $this->artisan('attendance:mark-absentees', ['date' => $testDate])
            ->assertExitCode(0);

        // Assert: Attendance record created
        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $testDate)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertEquals('absent', $attendance->status);
        $this->assertEquals(0.00, $attendance->leave_taken);
        $this->assertTrue($attendance->is_no_pay);
        $this->assertStringContainsString('Auto-marked absent', $attendance->remarks);
    }

    public function test_employees_on_approved_leave_are_skipped()
    {
        // Setup: Active employee and user
        [$employee, $user] = $this->createActiveEmployeeAndUser('EMP002', 'emp2@example.com', 'emp2');

        $testDate = '2026-06-09';

        // Create a test LeaveType
        $leaveType = \App\Models\LeaveType::create([
            'name' => 'Casual Leave',
            'code' => 'CL',
            'calculation_unit' => 'days',
            'default_allocation' => 14.00,
            'is_paid' => true,
            'is_active' => true,
        ]);

        // Create approved leave request for the day
        Leave::create([
            'employee_id' => $employee->id,
            'user_id' => $user->id,
            'leave_type_id' => $leaveType->id,
            'from_date' => $testDate,
            'to_date' => $testDate,
            'reason' => 'Casual Leave',
            'status' => 'approved'
        ]);

        // Run command
        $this->artisan('attendance:mark-absentees', ['date' => $testDate])
            ->assertExitCode(0);

        // Assert: No attendance record was created by the command
        $attendanceExists = Attendance::where('user_id', $user->id)
            ->whereDate('date', $testDate)
            ->exists();

        $this->assertFalse($attendanceExists);
    }

    public function test_employees_with_existing_attendance_are_skipped()
    {
        // Setup: Active employee and user
        [$employee, $user] = $this->createActiveEmployeeAndUser('EMP003', 'emp3@example.com', 'emp3');

        $testDate = '2026-06-09';

        // Create an existing attendance record (present)
        Attendance::create([
            'user_id' => $user->id,
            'employee_id' => $employee->id,
            'date' => $testDate,
            'status' => 'present',
            'clock_in' => '09:00:00'
        ]);

        // Run command
        $this->artisan('attendance:mark-absentees', ['date' => $testDate])
            ->assertExitCode(0);

        // Assert: Existing attendance record remains unmodified
        $attendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $testDate)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertEquals('present', $attendance->status);
        $this->assertNotEquals('absent', $attendance->status);
    }

    public function test_holidays_and_weekends_are_skipped()
    {
        // Setup: Active employee and user
        [$employee, $user] = $this->createActiveEmployeeAndUser('EMP004', 'emp4@example.com', 'emp4');

        // 2026-06-13 is a Saturday (weekend)
        $testWeekend = '2026-06-13';

        // Run command
        $this->artisan('attendance:mark-absentees', ['date' => $testWeekend])
            ->assertExitCode(0);

        // Assert: No attendance record was created (weekend is skipped)
        $attendanceExists = Attendance::where('user_id', $user->id)
            ->whereDate('date', $testWeekend)
            ->exists();

        $this->assertFalse($attendanceExists);
    }

    public function test_inactive_employees_are_skipped()
    {
        // Setup: Inactive employee
        $employee = Employee::create([
            'full_name' => 'Inactive Guy',
            'employee_code' => 'EMP005',
            'id_number' => 'NIC-EMP005',
            'date_of_birth' => '1990-01-01',
            'email' => 'inactive@example.com',
            'phone_primary' => '1234567890',
            'employment_status' => 'inactive', // inactive
            'is_active' => false,
            'joined_at' => '2026-06-01',
        ]);

        $user = User::create([
            'employee_id' => $employee->id,
            'name' => 'Inactive Guy',
            'username' => 'inactive',
            'email' => 'inactive@example.com',
            'password' => bcrypt('password'),
            'is_active' => false,
            'can_login' => false,
        ]);

        $testDate = '2026-06-10';

        // Run command
        $this->artisan('attendance:mark-absentees', ['date' => $testDate])
            ->assertExitCode(0);

        // Assert: No attendance record created
        $attendanceExists = Attendance::where('user_id', $user->id)
            ->whereDate('date', $testDate)
            ->exists();

        $this->assertFalse($attendanceExists);
    }

    public function test_today_is_skipped_if_run_before_16_15()
    {
        // Setup: Active employee and user
        [$employee, $user] = $this->createActiveEmployeeAndUser('EMP006', 'emp6@example.com', 'emp6');

        $testDate = '2026-06-10'; // Wednesday

        // Mock time to 2026-06-10 16:00:00 (before 16:15)
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 16, 0, 0));

        // Run command for today's date
        $this->artisan('attendance:mark-absentees', ['date' => $testDate])
            ->assertExitCode(0);

        // Assert: No attendance record created
        $attendanceExists = Attendance::where('user_id', $user->id)
            ->whereDate('date', $testDate)
            ->exists();

        $this->assertFalse($attendanceExists);

        // Clear mock time
        Carbon::setTestNow();
    }

    public function test_today_is_processed_if_run_at_or_after_16_15()
    {
        // Setup: Active employee and user
        [$employee, $user] = $this->createActiveEmployeeAndUser('EMP007', 'emp7@example.com', 'emp7');

        $testDate = '2026-06-10'; // Wednesday

        // Mock time to 2026-06-10 16:15:00 (at 16:15)
        Carbon::setTestNow(Carbon::create(2026, 6, 10, 16, 15, 0));

        // Run command for today's date
        $this->artisan('attendance:mark-absentees', ['date' => $testDate])
            ->assertExitCode(0);

        // Assert: Attendance record created
        $attendanceExists = Attendance::where('user_id', $user->id)
            ->whereDate('date', $testDate)
            ->exists();

        $this->assertTrue($attendanceExists);

        // Clear mock time
        Carbon::setTestNow();
    }

    public function test_unpaid_leave_balance_is_updated_on_absence()
    {
        // Setup: Active employee and user
        [$employee, $user] = $this->createActiveEmployeeAndUser('EMP008', 'emp8@example.com', 'emp8');

        // Create NOPAY leave type
        $leaveType = \App\Models\LeaveType::create([
            'name' => 'No Pay Leave',
            'code' => 'NOPAY',
            'calculation_unit' => 'days',
            'default_allocation' => 14.00,
            'is_paid' => false,
            'is_active' => true,
        ]);

        $testDate = '2026-06-09';

        // Run command
        $this->artisan('attendance:mark-absentees', ['date' => $testDate])
            ->assertExitCode(0);

        // Assert: Leave balance updated
        $balance = \App\Models\LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', 2026)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals(14.00, $balance->allocated);
        $this->assertEquals(1.00, $balance->used);
        $this->assertEquals(13.00, $balance->balance);
    }
}
