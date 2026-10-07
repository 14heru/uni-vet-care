@extends('layouts.customer')

@section('title', 'Book an appointment')

@section('content')
    <section class="customer-booking section-wrap" aria-labelledby="booking-page-title"
        data-availability-url="{{ route('customer.booking-availability') }}">
        <p class="eyebrow">Appointments for your pet</p>
        <h1 id="booking-page-title">Book a visit</h1>
        <p class="booking-introduction">Choose your pet, a doctor, and a date to see available appointment times.</p>

        @if ($errors->any())
            <div class="booking-alert booking-alert-error" role="alert">
                <p>Please review the following:</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($pets->isEmpty())
            <div class="booking-empty-state" role="status">
                <h2>No active pets found</h2>
                <p>Add an active pet to your customer profile before booking an appointment.</p>
            </div>
        @elseif ($doctors->isEmpty())
            <div class="booking-empty-state" role="status">
                <h2>No doctors are available right now</h2>
                <p>Please check again later or contact the clinic for help.</p>
            </div>
        @else
            <form class="booking-form" method="POST" action="{{ route('customer.bookings.store') }}" id="customer-booking-form">
                @csrf

                <div class="booking-form-grid">
                    <div class="booking-field">
                        <label for="pet_id">Your pet</label>
                        <select id="pet_id" name="pet_id" required>
                            <option value="">Choose a pet</option>
                            @foreach ($pets as $pet)
                                <option value="{{ $pet->id }}" @selected(old('pet_id') == $pet->id)>
                                    {{ $pet->name }} ({{ $pet->species }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="booking-field">
                        <label for="doctor_id">Doctor</label>
                        <select id="doctor_id" name="doctor_id" required>
                            <option value="">Choose a doctor</option>
                            @foreach ($doctors as $doctor)
                                <option value="{{ $doctor->id }}" @selected(old('doctor_id') == $doctor->id)>
                                    Dr. {{ $doctor->user->name }}{{ $doctor->specialization ? ' - '.$doctor->specialization : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="booking-field">
                        <label for="appointment_date">Appointment date</label>
                        <input id="appointment_date" type="date" min="{{ now('Asia/Jakarta')->toDateString() }}"
                            value="{{ old('appointment_start') ? substr(old('appointment_start'), 0, 10) : '' }}" required>
                    </div>
                </div>

                <input type="hidden" name="doctor_schedule_id" id="doctor_schedule_id" value="{{ old('doctor_schedule_id') }}">
                <input type="hidden" name="appointment_start" id="appointment_start" value="{{ old('appointment_start') }}">
                <input type="hidden" name="appointment_end" id="appointment_end" value="{{ old('appointment_end') }}">

                <section class="booking-slots" aria-labelledby="slots-title">
                    <div class="booking-section-heading">
                        <h2 id="slots-title">Available times</h2>
                        <p>Times are shown in Asia/Jakarta time.</p>
                    </div>
                    <p id="availability-message" class="availability-message" role="status" aria-live="polite">
                        Choose a doctor and date to view appointment times.
                    </p>
                    <div id="availability-slots" class="availability-slots"></div>
                </section>

                <button class="button customer-submit" id="booking-submit" type="submit" disabled>Confirm appointment</button>
            </form>
        @endif
    </section>

    @if ($pets->isNotEmpty() && $doctors->isNotEmpty())
        <script>
            (() => {
                const section = document.querySelector('.customer-booking');
                const doctor = document.getElementById('doctor_id');
                const date = document.getElementById('appointment_date');
                const slots = document.getElementById('availability-slots');
                const message = document.getElementById('availability-message');
                const submit = document.getElementById('booking-submit');
                const startInput = document.getElementById('appointment_start');
                const endInput = document.getElementById('appointment_end');
                const scheduleInput = document.getElementById('doctor_schedule_id');
                let requestNumber = 0;

                const clearSelection = () => {
                    startInput.value = '';
                    endInput.value = '';
                    scheduleInput.value = '';
                    submit.disabled = true;
                    slots.replaceChildren();
                };

                const loadSlots = async () => {
                    clearSelection();
                    if (!doctor.value || !date.value) {
                        message.textContent = 'Choose a doctor and date to view appointment times.';
                        return;
                    }

                    const currentRequest = ++requestNumber;
                    message.textContent = 'Checking available appointment times...';
                    const query = new URLSearchParams({ doctor_id: doctor.value, date: date.value });

                    try {
                        const response = await fetch(`${section.dataset.availabilityUrl}?${query}`, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        const result = await response.json();

                        if (currentRequest !== requestNumber) return;
                        if (!response.ok) throw new Error(result.message || 'Unable to load appointment times.');

                        if (!result.slots.length) {
                            message.textContent = 'No appointment times are available for this doctor and date. Please try another date.';
                            return;
                        }

                        message.textContent = 'Select an available appointment time.';
                        result.slots.forEach((slot) => {
                            const button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'slot-option';
                            button.setAttribute('aria-pressed', 'false');
                            button.textContent = `${slot.start_at.slice(11, 16)} - ${slot.end_at.slice(11, 16)}`;
                            button.addEventListener('click', () => {
                                slots.querySelectorAll('.slot-option').forEach((option) => option.setAttribute('aria-pressed', 'false'));
                                button.setAttribute('aria-pressed', 'true');
                                startInput.value = slot.start_at;
                                endInput.value = slot.end_at;
                                scheduleInput.value = slot.doctor_schedule_id;
                                submit.disabled = false;
                            });
                            slots.append(button);
                        });
                    } catch (error) {
                        if (currentRequest !== requestNumber) return;
                        message.textContent = error.message || 'Unable to load appointment times. Please try again.';
                    }
                };

                doctor.addEventListener('change', loadSlots);
                date.addEventListener('change', loadSlots);

                if (doctor.value && date.value) loadSlots();
            })();
        </script>
    @endif
@endsection
