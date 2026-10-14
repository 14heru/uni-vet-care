@extends('layouts.customer')

@section('title', 'Edit '.$pet->name)

@section('content')
    <section class="customer-booking section-wrap" aria-labelledby="pet-form-title">
        <p class="eyebrow">Your pet’s profile</p>
        <h1 id="pet-form-title">Edit {{ $pet->name }}</h1>
        <p class="booking-introduction">Update the details saved with your customer profile.</p>

        @include('customer.pets._form', [
            'action' => route('customer.pets.update', $pet),
            'method' => 'PUT',
            'submitLabel' => 'Save changes',
        ])
    </section>
@endsection
