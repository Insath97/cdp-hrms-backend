<?php

use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\PermissionController;
use App\Http\Controllers\V1\RoleController;
use App\Http\Controllers\V1\UserController;
use App\Http\Controllers\V1\DepartmentController;
use App\Http\Controllers\V1\EmployeeController;
use Illuminate\Support\Facades\Route;

/* public routes */
Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

/* protected routes */
Route::middleware(['auth:api'])->prefix('v1')->group(function () {

    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::get('permissions/list', [PermissionController::class, 'getPermissionList']);
    Route::apiResource('permissions', PermissionController::class);

    Route::get('roles/list/', [RoleController::class, 'getAvailableRoles']);
    Route::apiResource('roles', RoleController::class);

    Route::patch('departments/{id}/toggle-status', [DepartmentController::class, 'toggleStatus']);
    Route::get('departments/list', [DepartmentController::class, 'getDepartmentList']);
    Route::apiResource('departments', DepartmentController::class);

    Route::get('employees/list', [EmployeeController::class, 'getEmployeeList']);
    Route::post('employees/{employee}/restore', [EmployeeController::class, 'restore']);
    Route::delete('employees/{employee}/force-delete', [EmployeeController::class, 'forceDelete']);
    Route::patch('employees/{employee}/toggle-status', [EmployeeController::class, 'toggleStatus']);
    Route::patch('employees/{employee}/make-permanent', [EmployeeController::class, 'makePermanent']);
    Route::post('employees/{employee}/terminate', [EmployeeController::class, 'terminate']);
    Route::apiResource('employees', EmployeeController::class);

    Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::apiResource('users', UserController::class);
});
