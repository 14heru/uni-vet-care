@extends('layouts.public')

@section('title', 'Veterinary Care for Every Family')

@section('content')
    <section class="hero" aria-labelledby="hero-title">
        <div class="hero-pattern" aria-hidden="true"></div>
        <div class="hero-content">
            <div class="hero-emblem" aria-hidden="true">
                <svg viewBox="0 0 64 64" role="presentation">
                    <path d="M21 29c-5 0-9-6-9-12 0-4 2-7 5-7 5 0 9 7 9 13 0 4-2 6-5 6Zm22 0c5 0 9-6 9-12 0-4-2-7-5-7-5 0-9 7-9 13 0 4 2 6 5 6ZM12 38c-4 0-7-4-7-8 0-3 2-5 4-5 4 0 7 5 7 9 0 2-2 4-4 4Zm40 0c4 0 7-4 7-8 0-3-2-5-4-5-4 0-7 5-7 9 0 2 2 4 4 4ZM32 33c-8 0-18 10-18 18 0 5 4 8 9 8 3 0 6-2 9-2s6 2 9 2c5 0 9-3 9-8 0-8-10-18-18-18Z"/>
                </svg>
            </div>
            <p class="hero-kicker">Care that feels close to home</p>
            <h1 id="hero-title">Good health.<br><span>Happy paws.</span></h1>
            <p class="hero-copy">Welcome to Uni Vet Care, where every pet is met with gentle attention and every family is part of the care team.</p>
            <a class="button button-light" href="#booking">Explore appointment care <span aria-hidden="true">&rarr;</span></a>
            <a class="scroll-cue" href="#about"><span aria-hidden="true">&darr;</span> Get to know us</a>
        </div>
        <div class="hero-orbit orbit-one" aria-hidden="true"></div>
        <div class="hero-orbit orbit-two" aria-hidden="true"></div>
    </section>

    <section class="intro section-wrap" id="about" aria-labelledby="intro-title">
        <p class="eyebrow">A little more care in every visit</p>
        <h2 id="intro-title">Because they’re <em>family.</em></h2>
        <p class="intro-copy">From a curious new puppy to a well-loved old friend, pets deserve care that sees the whole story. Uni Vet Care is here to support healthier lives with compassion, clear guidance, and a calm approach.</p>
        <div class="divider-mark" aria-hidden="true"><span></span><b>✳</b><span></span></div>
    </section>

    <section class="services" id="services" aria-labelledby="services-title">
        <div class="section-wrap">
            <p class="eyebrow eyebrow-light">Care for the moments that matter</p>
            <h2 id="services-title">Here for every <em>chapter.</em></h2>
            <p class="section-lede">A good visit starts with listening. Our care is built around your pet’s needs and your family’s peace of mind.</p>

            <div class="service-grid">
                <article class="service-card">
                    <div class="service-icon" aria-hidden="true">
                        <svg viewBox="0 0 48 48"><path d="M24 40s-15-8.8-15-20a8 8 0 0 1 15-4 8 8 0 0 1 15 4c0 11.2-15 20-15 20Z"/><path d="M15 24h6l3-6 4 12 3-6h3"/></svg>
                    </div>
                    <h3>Everyday wellness</h3>
                    <p>Thoughtful support for keeping the little ones you love feeling their best.</p>
                </article>
                <article class="service-card">
                    <div class="service-icon" aria-hidden="true">
                        <svg viewBox="0 0 48 48"><circle cx="21" cy="21" r="12"/><path d="m30 30 10 10M21 14v14M14 21h14"/></svg>
                    </div>
                    <h3>Answers and guidance</h3>
                    <p>Clear explanations and caring attention when something doesn’t feel quite right.</p>
                </article>
                <article class="service-card">
                    <div class="service-icon" aria-hidden="true">
                        <svg viewBox="0 0 48 48"><path d="M24 7v34M7 24h34M12 12l24 24M36 12 12 36"/><circle cx="24" cy="24" r="17"/></svg>
                    </div>
                    <h3>Care through change</h3>
                    <p>Support for the new beginnings, changing needs, and every stage in between.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="care-note section-wrap" id="doctors" aria-labelledby="care-title">
        <div class="note-symbol" aria-hidden="true"><span>+</span></div>
        <div class="note-copy">
            <p class="eyebrow">People who care</p>
            <h2 id="care-title">Good care is a <em>conversation.</em></h2>
            <p>Our veterinary team believes in taking the time to understand your pet and answer your questions. Doctor profiles and clinic details will be available here as the site grows.</p>
        </div>
    </section>

    <section class="booking-band" id="booking" aria-labelledby="booking-title">
        <div class="booking-inner">
            <div>
                <p class="eyebrow eyebrow-light">A good first step</p>
                <h2 id="booking-title">Let’s make their next visit a good one.</h2>
                <p>Online appointment booking is coming soon. We’re getting everything ready to help you find the right care for your pet.</p>
            </div>
            <a class="button button-light" href="{{ route('customer.bookings.create') }}">Book an appointment <span aria-hidden="true">&rarr;</span></a>
        </div>
    </section>
@endsection
