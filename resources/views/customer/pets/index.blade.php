@extends('layouts.customer')

@section('title', 'My pets')

@section('content')
    <section class="customer-booking section-wrap" aria-labelledby="pets-title">
        <p class="eyebrow">Care starts at home</p>
        <h1 id="pets-title">My pets</h1>
        <p class="booking-introduction">Keep your pet details up to date for future appointments.</p>

        <p><a class="button customer-submit" href="{{ route('customer.pets.create') }}">Add a pet</a></p>

        @if ($pets->isEmpty())
            <div class="booking-empty-state" role="status">
                <h2>No pets added yet</h2>
                <p>Add a pet to make it available when booking an appointment.</p>
            </div>
        @else
            <div class="pet-list">
                @foreach ($pets as $pet)
                    <article class="pet-card" aria-labelledby="pet-name-{{ $pet->id }}">
                        <h2 id="pet-name-{{ $pet->id }}">
                            <a href="{{ route('customer.pets.show', $pet) }}">{{ $pet->name }}</a>
                        </h2>
                        <p>{{ $pet->species }}{{ $pet->breed ? ' · '.$pet->breed : '' }}</p>
                        <span class="pet-status {{ $pet->status === 'inactive' ? 'pet-status-inactive' : '' }}">
                            {{ ucfirst($pet->status) }}
                        </span>
                        <p><a class="booking-history-link" href="{{ route('customer.pets.edit', $pet) }}">Edit details</a></p>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
