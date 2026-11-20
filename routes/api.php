<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Support\Facades\Route;

$doctorOnly = RoleMiddleware::class . ':doctor';
$patientOnly = RoleMiddleware::class . ':patient';

// ---------- Public ----------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

Route::get('doctors', [DoctorController::class, 'index']);
Route::get('doctors/{doctor}', [DoctorController::class, 'show']);
Route::get('services', [ServiceController::class, 'index']);
Route::get('services/{service}', [ServiceController::class, 'show']);

// ---------- Logged-in (any role) ----------
Route::middleware('auth:sanctum')->group(function () use ($doctorOnly, $patientOnly) {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    Route::get('doctors/{doctor}/availability', [DoctorController::class, 'availability']);

    Route::get('appointments', [AppointmentController::class, 'index']);
    Route::get('appointments/{appointment}', [AppointmentController::class, 'show']);
    Route::patch('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);

    // ---------- Patient only ----------
    Route::middleware($patientOnly)->group(function () {
        Route::get('patient/profile', [PatientController::class, 'profile']);
        Route::put('patient/profile', [PatientController::class, 'updateProfile']);

        Route::post('appointments', [AppointmentController::class, 'store']);
        Route::put('appointments/{appointment}', [AppointmentController::class, 'update']);
    });

    // ---------- Doctor only ----------
    Route::middleware($doctorOnly)->group(function () {
        Route::get('doctor/profile', [DoctorController::class, 'profile']);
        Route::put('doctor/profile', [DoctorController::class, 'updateProfile']);
        Route::put('doctor/schedules', [DoctorController::class, 'setSchedules']);
        Route::get('doctor/patients', [DoctorController::class, 'patients']);

        Route::post('services', [ServiceController::class, 'store']);
        Route::put('services/{service}', [ServiceController::class, 'update']);
        Route::delete('services/{service}', [ServiceController::class, 'destroy']);

        Route::patch('appointments/{appointment}/complete', [AppointmentController::class, 'complete']);
    });
});
