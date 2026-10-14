@extends('layouts.customer')

@section('title', $pet->name)

@section('content')
    <section class="customer-booking section-wrap" aria-labelledby="pet-title">
        <p class="eyebrow">Your pet’s profile</p>
        <h1 id="pet-title">{{ $pet->name }}</h1>
        @if (session('status'))
            <p class="booking-introduction" role="status">{{ session('status') }}</p>
        @endif

        <dl class="pet-details">
            <div><dt>Species</dt><dd>{{ $pet->species }}</dd></div>
            <div><dt>Breed</dt><dd>{{ $pet->breed ?: 'Not provided' }}</dd></div>
            <div><dt>Sex</dt><dd>{{ $pet->sex ?: 'Not provided' }}</dd></div>
            <div><dt>Birth date</dt><dd>{{ $pet->birth_date ?: 'Not provided' }}</dd></div>
            <div><dt>Weight</dt><dd>{{ $pet->weight !== null ? $pet->weight.' kg' : 'Not provided' }}</dd></div>
            <div><dt>Color</dt><dd>{{ $pet->color ?: 'Not provided' }}</dd></div>
            <div>
                <dt>Booking availability</dt>
                <dd><span class="pet-status {{ $pet->status === 'inactive' ? 'pet-status-inactive' : '' }}">{{ ucfirst($pet->status) }}</span></dd>
            </div>
            <div><dt>Notes</dt><dd>{{ $pet->notes ?: 'None' }}</dd></div>
        </dl>

        <div class="pet-form-actions">
            <a class="button customer-submit" href="{{ route('customer.pets.edit', $pet) }}">Edit details</a>
            <a class="booking-history-link" href="{{ route('customer.pets.index') }}">Back to my pets</a>
        </div>
    </section>
@endsection
