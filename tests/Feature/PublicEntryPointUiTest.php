<?php

namespace Tests\Feature;

use Tests\TestCase;

class PublicEntryPointUiTest extends TestCase
{
    public function test_homepage_explains_booking_is_available_and_links_to_the_existing_booking_route(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('Choose your pet, a doctor, and an available time to request an appointment online.')
            ->assertSee('Book an appointment')
            ->assertSee('href="'.route('customer.bookings.create').'"', false)
            ->assertDontSee('coming soon');
    }

    public function test_public_navigation_has_a_visible_link_to_the_existing_sign_in_route(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertSee('Sign in')
            ->assertSee('class="site-nav-sign-in" href="'.route('login').'"', false);
    }

    public function test_guest_booking_entry_point_uses_the_existing_sign_in_flow(): void
    {
        $this->get(route('customer.bookings.create'))
            ->assertRedirect(route('login'));
    }
}
