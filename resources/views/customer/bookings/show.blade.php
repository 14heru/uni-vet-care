@php
    $statusPresentation = match ($booking->status) {
        \App\Models\Booking::STATUS_PENDING => [
            'title' => 'Booking request received',
            'eyebrow' => 'Awaiting confirmation',
            'message' => 'Your booking request is waiting for confirmation from the clinic.',
            'label' => 'Pending',
        ],
        \App\Models\Booking::STATUS_CONFIRMED => [
            'title' => 'Appointment confirmed',
            'eyebrow' => 'Confirmed appointment',
            'message' => 'Your appointment has been confirmed by the clinic.',
            'label' => 'Confirmed',
        ],
        \App\Models\Booking::STATUS_COMPLETED => [
            'title' => 'Appointment completed',
            'eyebrow' => 'Visit completed',
            'message' => 'Your pet’s appointment has been completed.',
            'label' => 'Completed',
        ],
        \App\Models\Booking::STATUS_CANCELLED => [
            'title' => 'Appointment cancelled',
            'eyebrow' => 'Booking cancelled',
            'message' => 'This appointment has been cancelled.',
            'label' => 'Cancelled',
        ],
        \App\Models\Booking::STATUS_NO_SHOW => [
            'title' => 'Appointment marked as no-show',
            'eyebrow' => 'Missed appointment',
            'message' => 'This appointment was marked as no-show because the customer did not attend.',
            'label' => 'No show',
        ],
        default => [
            'title' => 'Booking details',
            'eyebrow' => 'Booking status',
            'message' => 'Check the current status and details of your booking.',
            'label' => $booking->status,
        ],
    };
@endphp

@extends('layouts.customer')

@section('title', $statusPresentation['title'])

@section('content')
    <section class="customer-booking booking-result section-wrap" aria-labelledby="booking-result-title">
        <p class="eyebrow">{{ $statusPresentation['eyebrow'] }}</p>
        <h1 id="booking-result-title">{{ $statusPresentation['title'] }}</h1>
        <p class="booking-introduction">{{ $statusPresentation['message'] }} Keep the booking code for your records.</p>
        @if (session('status'))
            <p class="booking-introduction" role="status">{{ session('status') }}</p>
        @endif

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
                <dd><span class="booking-status-pill">{{ $statusPresentation['label'] }}</span></dd>
            </div>
        </dl>

        @if (in_array($booking->status, [\App\Models\Booking::STATUS_PENDING, \App\Models\Booking::STATUS_CONFIRMED], true))
            <form class="booking-form booking-cancellation-form" method="POST" action="{{ route('bookings.status.transition', $booking) }}">
                @csrf
                <input type="hidden" name="status" value="{{ \App\Models\Booking::STATUS_CANCELLED }}">
                <div class="booking-field">
                    <label for="cancellation_reason">Cancellation reason <span>(optional)</span></label>
                    <textarea id="cancellation_reason" name="reason" rows="3" maxlength="1000">{{ old('reason') }}</textarea>
                </div>
                <button class="button customer-submit" type="submit">Cancel appointment</button>
            </form>
        @endif

        <a class="button customer-submit" href="{{ route('customer.bookings.create') }}">Book another appointment</a>
    </section>
@endsection
