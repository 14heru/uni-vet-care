@extends('layouts.customer')

@section('title', 'Add a pet')

@section('content')
    <section class="customer-booking section-wrap" aria-labelledby="pet-form-title">
        <p class="eyebrow">Your pet’s profile</p>
        <h1 id="pet-form-title">Add a pet</h1>
        <p class="booking-introduction">Enter the details you would like to keep with your customer profile.</p>

        @include('customer.pets._form', [
            'action' => route('customer.pets.store'),
            'method' => 'POST',
            'submitLabel' => 'Save pet',
        ])
    </section>
@endsection
