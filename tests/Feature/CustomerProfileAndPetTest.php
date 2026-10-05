<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerProfileAndPetTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_one_customer_profile(): void
    {
        $user = User::factory()->create();
        $profile = $user->customerProfile()->create();

        $this->assertTrue($user->customerProfile->is($profile));
    }

    public function test_customer_profile_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $profile = $user->customerProfile()->create();

        $this->assertTrue($profile->user->is($user));
    }

    public function test_user_id_must_be_unique_for_customer_profiles(): void
    {
        $user = User::factory()->create();
        $user->customerProfile()->create();

        $this->expectException(QueryException::class);

        DB::table('customer_profiles')->insert([
            'user_id' => $user->id,
        ]);
    }

    public function test_customer_profile_rejects_a_nonexistent_user(): void
    {
        $this->expectException(QueryException::class);

        DB::table('customer_profiles')->insert([
            'user_id' => PHP_INT_MAX,
        ]);
    }

    public function test_deleting_a_user_with_a_customer_profile_is_restricted(): void
    {
        $user = User::factory()->create();
        $user->customerProfile()->create();

        $this->expectException(QueryException::class);

        $user->delete();
    }

    public function test_customer_profile_address_can_be_null(): void
    {
        $profile = User::factory()->create()->customerProfile()->create(['address' => null]);
        $profile->refresh();

        $this->assertNull($profile->address);
    }

    public function test_customer_profile_has_many_pets(): void
    {
        $profile = User::factory()->create()->customerProfile()->create();
        $firstPet = $profile->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $secondPet = $profile->pets()->create(['name' => 'Luna', 'species' => 'Dog']);

        $this->assertTrue($profile->pets()->whereKey($firstPet->getKey())->exists());
        $this->assertTrue($profile->pets()->whereKey($secondPet->getKey())->exists());
        $this->assertSame(2, $profile->pets()->count());
    }

    public function test_pet_belongs_to_customer_profile(): void
    {
        $profile = User::factory()->create()->customerProfile()->create();
        $pet = $profile->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        $this->assertTrue($pet->customer->is($profile));
    }

    public function test_customer_id_rejects_a_nonexistent_customer_profile(): void
    {
        $this->expectException(QueryException::class);

        DB::table('pets')->insert([
            'customer_id' => PHP_INT_MAX,
            'name' => 'Milo',
            'species' => 'Cat',
        ]);
    }

    public function test_deleting_a_customer_profile_with_pets_is_restricted(): void
    {
        $profile = User::factory()->create()->customerProfile()->create();
        $profile->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        $this->expectException(QueryException::class);

        $profile->delete();
    }

    public function test_pet_name_is_required(): void
    {
        $profile = User::factory()->create()->customerProfile()->create();

        $this->expectException(QueryException::class);

        DB::table('pets')->insert(['customer_id' => $profile->id, 'species' => 'Cat']);
    }

    public function test_pet_species_is_required(): void
    {
        $profile = User::factory()->create()->customerProfile()->create();

        $this->expectException(QueryException::class);

        DB::table('pets')->insert(['customer_id' => $profile->id, 'name' => 'Milo']);
    }

    public function test_pet_nullable_fields_accept_null_and_status_defaults_to_active(): void
    {
        $pet = User::factory()->create()->customerProfile()->create()->pets()->create([
            'name' => 'Milo',
            'species' => 'Cat',
            'breed' => null,
            'sex' => null,
            'birth_date' => null,
            'weight' => null,
            'color' => null,
            'notes' => null,
        ]);
        $pet->refresh();

        $this->assertSame('active', $pet->status);
        $this->assertNull($pet->breed);
        $this->assertNull($pet->sex);
        $this->assertNull($pet->birth_date);
        $this->assertNull($pet->weight);
        $this->assertNull($pet->color);
        $this->assertNull($pet->notes);
    }

    public function test_pet_weight_supports_decimal_six_two_values(): void
    {
        $pet = User::factory()->create()->customerProfile()->create()->pets()->create([
            'name' => 'Milo',
            'species' => 'Cat',
            'weight' => '9999.99',
        ]);
        $pet->refresh();

        $this->assertEquals(9999.99, (float) $pet->weight);
    }

    public function test_user_customer_profile_relationship_scopes_pets_to_that_profile(): void
    {
        $firstUser = User::factory()->create();
        $firstProfile = $firstUser->customerProfile()->create();
        $firstPet = $firstProfile->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        $secondProfile = User::factory()->create()->customerProfile()->create();
        $secondPet = $secondProfile->pets()->create(['name' => 'Luna', 'species' => 'Dog']);

        $this->assertTrue($firstUser->customerProfile->pets()->whereKey($firstPet->getKey())->exists());
        $this->assertFalse($firstUser->customerProfile->pets()->whereKey($secondPet->getKey())->exists());
    }
}
