@extends('layouts.doctor')

@section('title', 'My Appointments')

@section('content')
    <section class="doctor-dashboard section-wrap" aria-labelledby="doctor-dashboard-title">
        <p class="eyebrow">Doctor Dashboard</p>
        <h1 id="doctor-dashboard-title">My Appointments</h1>
        <p class="doctor-dashboard-introduction">View and monitor scheduled appointments for your patients.</p>

        @if ($bookings->isEmpty())
            <div class="booking-empty-state" role="status">
                <h2>No appointments scheduled</h2>
                <p>You do not have any appointments on your schedule at this time.</p>
            </div>
        @else
            <div class="doctor-table-container">
                <table class="doctor-appointments-table">
                    <thead>
                        <tr>
                            <th scope="col">Booking Code</th>
                            <th scope="col">Date &amp; Time</th>
                            <th scope="col">Customer</th>
                            <th scope="col">Pet</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr>
                                <td class="booking-code">{{ $booking->booking_code }}</td>
                                <td>
                                    {{ \Carbon\CarbonImmutable::parse($booking->appointment_start, 'Asia/Jakarta')->format('l, F j, Y') }}<br>
                                    <span style="color: var(--muted); font-size: .85rem;">
                                        {{ \Carbon\CarbonImmutable::parse($booking->appointment_start, 'Asia/Jakarta')->format('H:i') }} - {{ \Carbon\CarbonImmutable::parse($booking->appointment_end, 'Asia/Jakarta')->format('H:i') }} WIB
                                    </span>
                                </td>
                                <td>{{ $booking->customer?->user?->name ?? 'N/A' }}</td>
                                <td>
                                    <strong>{{ $booking->pet?->name ?? 'N/A' }}</strong><br>
                                    <span style="color: var(--muted); font-size: .85rem;">{{ $booking->pet?->species ?? '' }}</span>
                                </td>
                                <td>
                                    @php
                                        $badgeClass = match ($booking->status) {
                                            \App\Models\Booking::STATUS_CONFIRMED => 'status-badge-confirmed',
                                            \App\Models\Booking::STATUS_COMPLETED => 'status-badge-completed',
                                            \App\Models\Booking::STATUS_CANCELLED => 'status-badge-cancelled',
                                            \App\Models\Booking::STATUS_NO_SHOW => 'status-badge-no_show',
                                            default => 'status-badge-pending',
                                        };
                                    @endphp
                                    <span class="status-badge {{ $badgeClass }}">{{ $booking->status }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
