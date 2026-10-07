<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#272334">
    <title>@yield('title', 'Doctor dashboard') | Uni Vet Care</title>
    <link rel="stylesheet" href="{{ asset('css/public.css') }}">
    <link rel="stylesheet" href="{{ asset('css/doctor.css') }}">
</head>
<body class="doctor-dashboard-body">
    <a class="skip-link" href="#main-content">Skip to content</a>
    <header class="site-header">
        <div class="header-inner doctor-header-inner">
            <a class="brand" href="{{ route('home') }}" aria-label="Uni Vet Care home">
                <span class="brand-mark" aria-hidden="true">U</span>
                <span>Uni Vet Care</span>
            </a>
            <nav class="doctor-nav" aria-label="Doctor navigation">
                <span>Welcome, Dr. {{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="doctor-nav-link">Log out</button>
                </form>
            </nav>
        </div>
    </header>

    <main id="main-content" class="doctor-main">
        @yield('content')
    </main>

    <footer class="doctor-footer">
        <a class="brand footer-brand" href="{{ route('home') }}">
            <span class="brand-mark" aria-hidden="true">U</span>
            <span>Uni Vet Care</span>
        </a>
        <p>Thoughtful veterinary care, with your pet at the heart of it.</p>
    </footer>
</body>
</html>
