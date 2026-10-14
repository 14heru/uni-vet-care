<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Pet;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CustomerPetManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Jakarta'));
    }

    public function test_customer_pet_list_is_scoped_and_has_an_empty_state(): void
    {
        $customer = $this->createCustomer();
        $customer['profile']->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $other = $this->createCustomer();
        $other['profile']->pets()->create(['name' => 'Private Luna', 'species' => 'Dog']);

        $this->actingAs($customer['user'])
            ->get(route('customer.pets.index'))
            ->assertOk()
            ->assertSee('Milo')
            ->assertDontSee('Private Luna')
            ->assertSee('Add a pet');

        $emptyCustomer = $this->createCustomer();
        $this->actingAs($emptyCustomer['user'])
            ->get(route('customer.pets.index'))
            ->assertOk()
            ->assertSee('No pets added yet');
    }

    public function test_customer_can_add_and_view_a_pet(): void
    {
        $customer = $this->createCustomer();

        $response = $this->actingAs($customer['user'])
            ->post(route('customer.pets.store'), [
                'name' => 'Milo',
                'species' => 'Cat',
                'breed' => 'Domestic shorthair',
                'sex' => 'Male',
                'birth_date' => '2022-04-10',
                'weight' => '4.25',
                'color' => 'Grey',
                'notes' => 'Friendly',
                'status' => 'active',
            ]);

        $pet = Pet::query()->sole();
        $response->assertRedirect(route('customer.pets.show', $pet));
        $this->assertSame($customer['profile']->id, $pet->customer_id);

        $this->get(route('customer.pets.show', $pet))
            ->assertOk()
            ->assertSee('Milo')
            ->assertSee('Domestic shorthair')
            ->assertSee('4.25 kg');
    }

    public function test_customer_can_update_their_pet_without_changing_ownership(): void
    {
        $customer = $this->createCustomer();
        $pet = $customer['profile']->pets()->create([
            'name' => 'Milo',
            'species' => 'Cat',
            'status' => 'active',
        ]);

        $this->actingAs($customer['user'])
            ->put(route('customer.pets.update', $pet), [
                'name' => 'Milo Two',
                'species' => 'Cat',
                'breed' => 'Tabby',
                'sex' => 'Male',
                'birth_date' => '2022-04-10',
                'weight' => '4.25',
                'color' => 'Brown',
                'notes' => 'Updated details',
                'status' => 'inactive',
            ])
            ->assertRedirect(route('customer.pets.show', $pet));

        $pet->refresh();
        $this->assertSame($customer['profile']->id, $pet->customer_id);
        $this->assertSame('Milo Two', $pet->name);
        $this->assertSame('inactive', $pet->status);
        $this->assertSame('4.25', $pet->weight);
    }

    public function test_detail_edit_and_update_use_the_requested_pet_owned_by_the_customer(): void
    {
        $customer = $this->createCustomer();
        $firstPet = $customer['profile']->pets()->create(['name' => 'First Milo', 'species' => 'Cat']);
        $requestedPet = $customer['profile']->pets()->create(['name' => 'Requested Luna', 'species' => 'Dog']);

        $this->actingAs($customer['user']);

        $this->get(route('customer.pets.show', $requestedPet))
            ->assertOk()
            ->assertSee('Requested Luna')
            ->assertDontSee('First Milo');

        $this->get(route('customer.pets.edit', $requestedPet))
            ->assertOk()
            ->assertSee('Edit Requested Luna')
            ->assertSee('value="Requested Luna"', false)
            ->assertDontSee('value="First Milo"', false);

        $this->put(route('customer.pets.update', $requestedPet), $this->validPetInput([
            'name' => 'Updated Luna',
            'species' => 'Dog',
        ]))->assertRedirect(route('customer.pets.show', $requestedPet));

        $this->assertSame('First Milo', $firstPet->fresh()->name);
        $this->assertSame('Updated Luna', $requestedPet->fresh()->name);
    }

    public function test_customer_cannot_read_or_update_another_customers_pet(): void
    {
        $customer = $this->createCustomer();
        $other = $this->createCustomer();
        $pet = $other['profile']->pets()->create(['name' => 'Private Luna', 'species' => 'Dog']);

        $this->actingAs($customer['user']);
        $this->get(route('customer.pets.show', $pet))
            ->assertNotFound()
            ->assertDontSee('Private Luna');
        $this->get(route('customer.pets.edit', $pet))->assertNotFound();
        $this->put(route('customer.pets.update', $pet), $this->validPetInput(['name' => 'Stolen']))
            ->assertNotFound();

        $this->assertSame('Private Luna', $pet->fresh()->name);
        $this->assertSame($other['profile']->id, $pet->fresh()->customer_id);
    }

    public function test_customer_id_input_is_rejected_and_cannot_transfer_pet_ownership(): void
    {
        $customer = $this->createCustomer();
        $other = $this->createCustomer();

        $this->actingAs($customer['user'])
            ->post(route('customer.pets.store'), $this->validPetInput([
                'customer_id' => $other['profile']->id,
            ]))
            ->assertSessionHasErrors('customer_id');

        $pet = $customer['profile']->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $this->put(route('customer.pets.update', $pet), $this->validPetInput([
            'name' => 'Milo Updated',
            'customer_id' => $other['profile']->id,
        ]))->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('pets', 1);
        $this->assertSame($customer['profile']->id, $pet->fresh()->customer_id);
        $this->assertSame('Milo', $pet->fresh()->name);
    }

    public function test_invalid_pet_data_is_rejected(): void
    {
        $customer = $this->createCustomer();
        $this->actingAs($customer['user']);

        $this->post(route('customer.pets.store'), $this->validPetInput([
            'name' => '',
            'species' => '',
            'birth_date' => '2027-01-01',
            'weight' => '10000.00',
        ]))->assertSessionHasErrors(['name', 'species', 'birth_date', 'weight']);

        $this->assertDatabaseCount('pets', 0);
    }

    public function test_guest_other_roles_and_inactive_customer_cannot_access_pet_management(): void
    {
        $this->get(route('customer.pets.index'))->assertRedirect(route('login'));

        foreach (['doctor', 'admin', 'owner'] as $roleName) {
            $user = $this->createUser($roleName);
            $this->actingAs($user)->get(route('customer.pets.index'))->assertForbidden();
        }

        $customer = $this->createCustomer();
        $this->actingAs($customer['user']);
        $customer['user']->forceFill(['status' => 'inactive'])->save();

        $this->get(route('customer.pets.index'))->assertForbidden();
        $this->get(route('customer.pets.create'))->assertForbidden();
        $this->post(route('customer.pets.store'), $this->validPetInput())->assertForbidden();
    }

    public function test_editing_a_pet_preserves_its_booking_and_no_delete_route_exists(): void
    {
        $customer = $this->createCustomer();
        $pet = $customer['profile']->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $doctor = $this->createDoctor();
        $booking = new Booking;
        $booking->forceFill([
            'booking_code' => 'UVC-PET-HISTORY-001',
            'customer_id' => $customer['profile']->id,
            'pet_id' => $pet->id,
            'doctor_id' => $doctor['profile']->id,
            'doctor_schedule_id' => $doctor['schedule']->id,
            'appointment_start' => '2026-10-05 09:00:00',
            'appointment_end' => '2026-10-05 09:30:00',
            'status' => Booking::STATUS_COMPLETED,
            'slot_key' => null,
        ]);
        $booking->save();

        $this->actingAs($customer['user'])
            ->put(route('customer.pets.update', $pet), $this->validPetInput(['name' => 'Milo Updated']))
            ->assertRedirect(route('customer.pets.show', $pet));

        $this->assertSame($pet->id, $booking->fresh()->pet_id);
        $this->assertSame('UVC-PET-HISTORY-001', $booking->fresh()->booking_code);
        $this->assertFalse(Route::has('customer.pets.destroy'));
    }

    public function test_booking_page_still_offers_only_active_pets_belonging_to_customer(): void
    {
        $customer = $this->createCustomer();
        $customer['profile']->pets()->create(['name' => 'Active Milo', 'species' => 'Cat']);
        $customer['profile']->pets()->create(['name' => 'Inactive Pet', 'species' => 'Dog', 'status' => 'inactive']);
        $other = $this->createCustomer();
        $other['profile']->pets()->create(['name' => 'Private Pet', 'species' => 'Rabbit']);
        $this->createDoctor();

        $this->actingAs($customer['user'])
            ->get(route('customer.bookings.create'))
            ->assertOk()
            ->assertSee('Active Milo')
            ->assertDontSee('Inactive Pet')
            ->assertDontSee('Private Pet');
    }

    /** @return array<string, mixed> */
    private function validPetInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Milo',
            'species' => 'Cat',
            'breed' => null,
            'sex' => null,
            'birth_date' => null,
            'weight' => null,
            'color' => null,
            'notes' => null,
            'status' => 'active',
        ], $overrides);
    }

    /** @return array{user: User, profile: CustomerProfile} */
    private function createCustomer(): array
    {
        $user = $this->createUser('customer');

        return ['user' => $user, 'profile' => $user->customerProfile()->create()];
    }

    private function createUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        return User::factory()->for($role)->create(['status' => 'active']);
    }

    /** @return array{profile: DoctorProfile, schedule: DoctorSchedule} */
    private function createDoctor(): array
    {
        $user = $this->createUser('doctor');
        $profile = $user->doctorProfile()->create(['specialization' => 'General care']);
        $schedule = $profile->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
        ]);

        return compact('profile', 'schedule');
    }
}
