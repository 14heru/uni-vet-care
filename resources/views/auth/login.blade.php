@extends('layouts.public')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/customer-booking.css') }}">
@endpush

@section('title', 'Customer sign in')

@section('content')
    <section class="customer-booking section-wrap" aria-labelledby="login-title">
        <p class="eyebrow">Welcome back</p>
        <h1 id="login-title">Customer sign in</h1>
        <p class="booking-introduction">Sign in to book an appointment for your pet.</p>

        @if ($errors->any())
            <div class="booking-alert booking-alert-error" role="alert">
                <p>We couldn't sign you in with those details.</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="booking-form" method="POST" action="{{ route('login') }}">
            @csrf
            <div class="booking-form-grid">
                <div class="booking-field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
                </div>
                <div class="booking-field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" autocomplete="current-password" required>
                </div>
            </div>
            <button class="button customer-submit" type="submit">Sign in</button>
        </form>
    </section>
@endsection
