<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#272334">
    <meta name="description" content="Uni Vet Care provides thoughtful veterinary care for the pets who make a house a home.">
    <title>@yield('title', 'Uni Vet Care') | Uni Vet Care</title>
    <link rel="stylesheet" href="{{ asset('css/public.css') }}">
</head>
<body>
    <a class="skip-link" href="#main-content">Skip to content</a>

    <header class="site-header">
        <div class="header-inner">
            <a class="brand" href="{{ route('home') }}" aria-label="Uni Vet Care home">
                <span class="brand-mark" aria-hidden="true">U</span>
                <span>Uni Vet Care</span>
            </a>
            <nav class="site-nav" aria-label="Main navigation">
                <a href="#about">About</a>
                <a href="#services">Services</a>
                <a href="#doctors">Doctors</a>
                <a href="#contact">Contact</a>
            </nav>
        </div>
    </header>

    <main id="main-content">
        @yield('content')
    </main>

    <footer class="site-footer" id="contact">
        <div class="footer-inner">
            <div>
                <a class="brand footer-brand" href="{{ route('home') }}">
                    <span class="brand-mark" aria-hidden="true">U</span>
                    <span>Uni Vet Care</span>
                </a>
                <p>Thoughtful veterinary care, with your pet at the heart of it.</p>
            </div>
            <div class="footer-note">
                <span class="eyebrow">Here for your family</span>
                <p>More clinic information and ways to get in touch will be added soon.</p>
            </div>
        </div>
        <div class="copyright">&copy; {{ date('Y') }} Uni Vet Care</div>
    </footer>
</body>
</html>
