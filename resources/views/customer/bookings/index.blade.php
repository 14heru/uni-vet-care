@extends('layouts.customer')

@section('title', 'My bookings')

@section('content')
    <section class="customer-booking section-wrap" aria-labelledby="booking-history-title">
        <p class="eyebrow">Your appointments</p>
        <h1 id="booking-history-title">My bookings</h1>
        <p class="booking-introduction">Review your appointment details and current status.</p>

        @if ($bookings->isEmpty())
            <div class="booking-empty-state" role="status">
                <h2>No bookings yet</h2>
                <p>Your appointment bookings will appear here.</p>
                <a class="button customer-submit" href="{{ route('customer.bookings.create') }}">Book an appointment</a>
            </div>
        @else
            <div class="booking-history-list">
                @foreach ($bookings as $booking)
                    <article class="booking-history-item" aria-labelledby="booking-code-{{ $booking->id }}">
                        <h2 id="booking-code-{{ $booking->id }}">
                            <a href="{{ route('customer.bookings.show', $booking) }}">{{ $booking->booking_code }}</a>
                        </h2>
                        <dl class="booking-history-details">
                            <div>
                                <dt>Date and time</dt>
                                <dd>{{ \Carbon\CarbonImmutable::parse($booking->appointment_start, 'Asia/Jakarta')->format('l, F j, Y - H:i') }} to {{ \Carbon\CarbonImmutable::parse($booking->appointment_end, 'Asia/Jakarta')->format('H:i') }} WIB</dd>
                            </div>
                            <div>
                                <dt>Doctor</dt>
                                <dd>{{ $booking->doctor?->user?->name ? 'Dr. '.$booking->doctor->user->name : 'Doctor information unavailable' }}</dd>
                            </div>
                            <div>
                                <dt>Pet</dt>
                                <dd>{{ $booking->pet?->name ?? 'Pet information unavailable' }}{{ $booking->pet?->species ? ' ('.$booking->pet->species.')' : '' }}</dd>
                            </div>
                            <div>
                                <dt>Status</dt>
                                <dd><span class="booking-status-pill">{{ ucwords(strtolower(str_replace('_', ' ', $booking->status))) }}</span></dd>
                            </div>
                        </dl>
                        <a class="booking-history-link" href="{{ route('customer.bookings.show', $booking) }}">View booking details</a>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
