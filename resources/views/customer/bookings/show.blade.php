@extends('layouts.customer')

@section('title', 'Appointment confirmed')

@section('content')
    <section class="customer-booking booking-result section-wrap" aria-labelledby="booking-result-title">
        <p class="eyebrow">Your booking request is received</p>
        <h1 id="booking-result-title">You're all set.</h1>
        <p class="booking-introduction">Your booking has been received. Keep the booking code for your records.</p>

        <dl class="booking-result-details">
            <div>
                <dt>Booking code</dt>
                <dd class="booking-code">{{ $booking->booking_code }}</dd>
            </div>
            <div>
                <dt>Date and time</dt>
                <dd>{{ \Carbon\CarbonImmutable::parse($booking->appointment_start, 'Asia/Jakarta')->format('l, F j, Y - H:i') }} to {{ \Carbon\CarbonImmutable::parse($booking->appointment_end, 'Asia/Jakarta')->format('H:i') }} WIB</dd>
            </div>
            <div>
                <dt>Doctor</dt>
                <dd>Dr. {{ $booking->doctor->user->name }}</dd>
            </div>
            <div>
                <dt>Pet</dt>
                <dd>{{ $booking->pet->name }} ({{ $booking->pet->species }})</dd>
            </div>
            <div>
                <dt>Current status</dt>
                <dd><span class="booking-status-pill">{{ $booking->status }}</span></dd>
            </div>
        </dl>

        <a class="button customer-submit" href="{{ route('customer.bookings.create') }}">Book another appointment</a>
    </section>
@endsection
