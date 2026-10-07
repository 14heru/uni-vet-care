<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#272334">
    <title>@yield('title', 'Customer appointments') | Uni Vet Care</title>
    <link rel="stylesheet" href="{{ asset('css/public.css') }}">
    <link rel="stylesheet" href="{{ asset('css/customer-booking.css') }}">
</head>
<body class="customer-booking-body">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="site-header">
        <div class="header-inner customer-header-inner">
            <a class="brand" href="{{ route('home') }}" aria-label="Uni Vet Care home">
                <span class="brand-mark" aria-hidden="true">U</span>
                <span>Uni Vet Care</span>
            </a>
            <nav class="customer-nav" aria-label="Customer navigation">
                <span>Welcome, {{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="customer-nav-link">Log out</button>
                </form>
            </nav>
        </div>
    </header>

    <main id="main-content" class="customer-main">
        @yield('content')
    </main>

    <footer class="customer-footer">
        <a class="brand footer-brand" href="{{ route('home') }}">
            <span class="brand-mark" aria-hidden="true">U</span>
            <span>Uni Vet Care</span>
        </a>
        <p>Thoughtful veterinary care, with your pet at the heart of it.</p>
    </footer>
</body>
</html>
