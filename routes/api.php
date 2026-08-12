<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DailyClosureController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ShopController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware('api')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::put('auth/profile', [AuthController::class, 'updateProfile']);
    Route::put('auth/password', [AuthController::class, 'updatePassword']);
    Route::get('attendance/today', [AttendanceController::class, 'today']);
    Route::post('attendance/check-in', [AttendanceController::class, 'checkIn']);
    Route::post('attendance/check-out', [AttendanceController::class, 'checkOut']);
    Route::get('audit', [AuditController::class, 'index']);
    Route::get('summary', [ShopController::class, 'summary']);
    Route::get('shops', [ShopController::class, 'index']);
    Route::post('shops', [ShopController::class, 'store']);
    Route::get('shops/{shop}', [ShopController::class, 'show']);
    Route::put('shops/{shop}', [ShopController::class, 'update']);
    Route::delete('shops/{shop}', [ShopController::class, 'destroy']);
    Route::get('shops/{shop}/employees', [ShopController::class, 'employees']);
    Route::post('shops/{shop}/employees', [EmployeeController::class, 'store']);
    Route::put('shops/{shop}/employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('shops/{shop}/employees/{employee}', [EmployeeController::class, 'destroy']);
    Route::get('attendance', [AttendanceController::class, 'index']);
    Route::get('shops/{shop}/cash', [ShopController::class, 'cash']);
    Route::get('shops/{shop}/operations', [OperationController::class, 'index']);
    Route::get('shops/{shop}/operations-summary', [OperationController::class, 'summary']);
    Route::post('shops/{shop}/operations', [OperationController::class, 'store']);
    Route::put('shops/{shop}/operations/{operation}', [OperationController::class, 'update']);
    Route::delete('shops/{shop}/operations/{operation}', [OperationController::class, 'destroy']);
    Route::get('shops/{shop}/reports', [ReportController::class, 'index']);
    Route::get('shops/{shop}/reports-export', [ReportController::class, 'export']);
    Route::get('shops/{shop}/reports/{report}', [ReportController::class, 'show']);
    Route::delete('shops/{shop}/reports', [ReportController::class, 'destroyAll']);
    Route::post('shops/{shop}/reports/delete-selection', [ReportController::class, 'destroySelection']);
    Route::delete('shops/{shop}/reports/{report}', [ReportController::class, 'destroy']);
    Route::get('shops/{shop}/closures', [DailyClosureController::class, 'index']);
    Route::get('shops/{shop}/closures/today', [DailyClosureController::class, 'today']);
    Route::post('shops/{shop}/closures', [DailyClosureController::class, 'store']);
    Route::post('shops/{shop}/cash-adjustments', [ShopController::class, 'adjustCash']);
    Route::get('cash-adjustments', [ShopController::class, 'cashAdjustments']);
});
