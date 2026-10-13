<?php

use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\HomeController;
use App\Http\Controllers\Dashboard\UsersController;
use Illuminate\Support\Facades\Route;

// app.<domain> — restaurant dashboard. The tenant is the logged-in user's restaurant (InitializeTenancyFromUser).
Route::middleware('admin.locale')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
});

Route::middleware(['auth:web', 'tenant.user'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/locale', [AuthController::class, 'locale'])->name('locale');

    Route::get('/', [HomeController::class, 'index'])->name('home');

    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/users', [UsersController::class, 'index'])->name('users');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
        Route::post('/users/{user}', [UsersController::class, 'update'])->name('users.update');
    });
});
