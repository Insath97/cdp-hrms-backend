<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\PermissionController;
use App\Http\Controllers\V1\RoleController;
use App\Http\Controllers\V1\UserController;
use App\Http\Controllers\V1\DepartmentController;
use App\Http\Controllers\V1\EmployeeController;
use App\Http\Controllers\V1\ProvinceController;
use App\Http\Controllers\V1\RegionController;
use App\Http\Controllers\V1\ZonalController;
use App\Http\Controllers\V1\BranchController;
use App\Http\Controllers\V1\DesignationController;
use App\Http\Controllers\V1\LeaveTypeController;
use App\Http\Controllers\V1\LeaveController;
use App\Http\Controllers\V1\LetterController;
use App\Http\Controllers\V1\AttendanceController;
use App\Http\Controllers\V1\TableCountController;
use App\Http\Controllers\V1\FingerprintController;
use App\Http\Controllers\V1\FingerprintWebhookController;
use App\Http\Controllers\V1\PayrollController;
use App\Http\Controllers\V1\PayrollAdminController;
use App\Http\Controllers\V1\ImportController;
use Illuminate\Support\Facades\Route;


/* public routes */

Route::prefix('v1')->group(function () {
    // Login endpoint
    Route::post('login', [AuthController::class, 'login']);
    
    // Public employee verification (for QR code scanning)
    Route::get('public/verify-employee/{employeeCode}', [EmployeeController::class, 'verifyByCode'])
        ->name('public.verify.employee');
    
    // Add other public routes here if needed
    // Route::get('public/...', ...);
});

/* protected routes */
Route::middleware(['auth:api'])->prefix('v1')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    // Route::get('table-counts', [TableCountController::class, 'getTableCounts']);
    // Route::get('table-counts/{tableName}', [TableCountController::class, 'getTableCount']);
    // Route::get('employee-counts/inactive', [TableCountController::class, 'getInactiveEmployeeCount']);
    // Route::get('employee-counts/by-type', [TableCountController::class, 'getEmployeeTypeCounts']);

    Route::get('permissions/list', [PermissionController::class, 'getPermissionList']);
    Route::apiResource('permissions', PermissionController::class);

    Route::get('roles/list/', [RoleController::class, 'getAvailableRoles']);
    Route::apiResource('roles', RoleController::class);

    Route::get('provinces/list', [ProvinceController::class, 'getProvinceList']);
    Route::patch('provinces/{id}/toggle-status', [ProvinceController::class, 'toggleStatus']);
    Route::apiResource('provinces', ProvinceController::class);

    Route::get('regions/list', [RegionController::class, 'getRegionList']);
    Route::patch('regions/{id}/toggle-status', [RegionController::class, 'toggleStatus']);
    Route::apiResource('regions', RegionController::class);

    Route::get('zonals/list', [ZonalController::class, 'getZonalList']);
    Route::patch('zonals/{id}/toggle-status', [ZonalController::class, 'toggleStatus']);
    Route::apiResource('zonals', ZonalController::class);

    Route::get('branches/list', [BranchController::class, 'getBranchList']);
    Route::patch('branches/{id}/toggle-status', [BranchController::class, 'toggleStatus']);
    Route::apiResource('branches', BranchController::class);

    Route::get('designations/list', [DesignationController::class, 'getDesignationList']);
    Route::patch('designations/{id}/toggle-status', [DesignationController::class, 'toggleStatus']);
    Route::apiResource('designations', DesignationController::class);

    Route::patch('departments/{id}/toggle-status', [DepartmentController::class, 'toggleStatus']);
    Route::get('departments/list', [DepartmentController::class, 'getDepartmentList']);
    Route::apiResource('departments', DepartmentController::class);

    Route::get('leave-types/list', [LeaveTypeController::class, 'getLeaveTypeList']);
    Route::patch('leave-types/{id}/toggle-status', [LeaveTypeController::class, 'toggleStatus']);
    Route::apiResource('leave-types', LeaveTypeController::class);

    Route::post('leaves/{id}/approve', [LeaveController::class, 'approve']);
    Route::post('leaves/{id}/reject', [LeaveController::class, 'reject']);
    Route::apiResource('leaves', LeaveController::class);

    Route::apiResource('letters', LetterController::class);

    Route::prefix('attendances')->group(function () {
        Route::get('report', [AttendanceController::class, 'report']);
        Route::get('report/daily', [AttendanceController::class, 'dailyReport']);
        Route::get('report/weekly', [AttendanceController::class, 'weeklyReport']);
        Route::get('report/monthly', [AttendanceController::class, 'monthlyReport']);
        Route::post('clock-out', [AttendanceController::class, 'clockOut']);
    });
    Route::put('attendances', [AttendanceController::class, 'update']);
    Route::apiResource('attendances', AttendanceController::class);

    
    Route::get('employees/list', [EmployeeController::class, 'getEmployeeList']);
    Route::post('employees/{employee}/restore', [EmployeeController::class, 'restore']);
    Route::delete('employees/{employee}/force-delete', [EmployeeController::class, 'forceDelete']);
    Route::patch('employees/{employee}/toggle-status', [EmployeeController::class, 'toggleStatus']);
    Route::patch('employees/{employee}/make-permanent', [EmployeeController::class, 'makePermanent']);
    Route::post('employees/{employee}/terminate', [EmployeeController::class, 'terminate']);
    Route::apiResource('employees', EmployeeController::class);

    Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::apiResource('users', UserController::class);

    // Fingerprint Device Routes
    Route::prefix('fingerprint')->middleware(['auth:sanctum', 'permission:manage fingerprint'])->group(function () {
        
        // Device Management
        Route::get('/test-connection', [FingerprintController::class, 'testConnection']);
        Route::get('/device-info', [FingerprintController::class, 'getDeviceInfo']);
        Route::get('/device-users', [FingerprintController::class, 'getDeviceUsers']);
        Route::get('/device-statistics', [FingerprintController::class, 'getDeviceStatistics']);
        
        // Attendance Logs
        Route::get('/attendance-logs', [FingerprintController::class, 'getAttendanceLogs']);
        Route::get('/attendance-today', [FingerprintController::class, 'getTodayAttendance']);
        
        // Sync Operations
        Route::post('/sync-user', [FingerprintController::class, 'syncUserToDevice']);
        Route::post('/sync-attendance', [FingerprintController::class, 'syncAttendanceToHRMS']);
        Route::get('/compare-attendance', [FingerprintController::class, 'compareAttendance']);
        
        // Fingerprint Management
        Route::post('/register-fingerprint', [FingerprintController::class, 'registerFingerprint']);
        Route::delete('/delete-fingerprint', [FingerprintController::class, 'deleteFingerprint']);
        
        // Manual Operations
        Route::post('/manual-attendance', [FingerprintController::class, 'manualAttendance']);
    });

    // Webhook endpoint (no authentication, device will call this)
    Route::post('/webhooks/fingerprint', [FingerprintWebhookController::class, 'handleWebhook']);

    
    // Employee routes
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/payroll', [PayrollController::class, 'index']);
        Route::post('/payroll/{payrollRecord}/request', [PayrollController::class, 'requestPayslip']);
        Route::get('/payroll/{payrollRecord}/status', [PayrollController::class, 'getRequestStatus']);
        Route::get('/payroll/{payrollRecord}/print', [PayrollController::class, 'printPayslip']);
    });

    // HR Admin routes
    Route::middleware(['auth:sanctum'])->prefix('admin')->group(function () {
        Route::get('/payroll/requests/pending', [PayrollAdminController::class, 'pendingRequests']);
        Route::post('/payroll/requests/{payslipRequest}/approve', [PayrollAdminController::class, 'approveRequest']);
        Route::post('/payroll/requests/{payslipRequest}/reject', [PayrollAdminController::class, 'rejectRequest']);
        Route::post('/payroll/bulk-generate', [PayrollAdminController::class, 'bulkGenerate']);
    });

    //Bulk Import routes
    Route::get('imports/tables/list', [ImportController::class, 'listTables']);
    Route::get('imports', [ImportController::class, 'index']);
    Route::post('imports/{table}', [ImportController::class, 'import']);
});
