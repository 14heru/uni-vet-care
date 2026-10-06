<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BookingStatusController;
use App\Http\Controllers\Customer\BookingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::post('/customer/bookings', [BookingController::class, 'store'])
    ->middleware(['auth', 'role:customer,admin'])
    ->name('customer.bookings.store');

Route::post('/bookings/{booking}/status', [BookingStatusController::class, 'transition'])
    ->middleware('auth')
    ->name('bookings.status.transition');
