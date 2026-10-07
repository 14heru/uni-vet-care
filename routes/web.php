<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BookingStatusController;
use App\Http\Controllers\Customer\BookingController;
use App\Http\Controllers\Customer\BookingPageController;
use App\Http\Controllers\Doctor\BookingController as DoctorBookingController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'public.home')->name('home');

Route::view('/login', 'auth.login')->middleware('guest');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

Route::middleware(['auth', 'role:customer'])->prefix('customer')->name('customer.')->group(function () {
    Route::get('/bookings/create', [BookingPageController::class, 'create'])->name('bookings.create');
    Route::get('/booking-availability', [BookingPageController::class, 'availability'])->name('booking-availability');
    Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
});

Route::post('/customer/bookings', [BookingController::class, 'store'])
    ->middleware(['auth', 'role:customer,admin'])
    ->name('customer.bookings.store');

Route::middleware(['auth', 'role:doctor'])->prefix('doctor')->name('doctor.')->group(function () {
    Route::get('/bookings', [DoctorBookingController::class, 'index'])->name('bookings.index');
});

Route::post('/bookings/{booking}/status', [BookingStatusController::class, 'transition'])
    ->middleware('auth')
    ->name('bookings.status.transition');
