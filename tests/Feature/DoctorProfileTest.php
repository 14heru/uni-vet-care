<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DoctorProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_one_doctor_profile(): void
    {
        $user = User::factory()->create();
        $profile = $user->doctorProfile()->create();

        $this->assertTrue($user->doctorProfile->is($profile));
    }

    public function test_doctor_profile_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $profile = $user->doctorProfile()->create();

        $this->assertTrue($profile->user->is($user));
    }

    public function test_user_id_must_be_unique(): void
    {
        $user = User::factory()->create();
        $user->doctorProfile()->create();

        $this->expectException(QueryException::class);

        DB::table('doctor_profiles')->insert([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
    }

    public function test_non_null_license_number_must_be_unique(): void
    {
        $licenseNumber = 'LICENSE-123';
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $firstUser->doctorProfile()->create(['license_number' => $licenseNumber]);

        $this->expectException(QueryException::class);

        $secondUser->doctorProfile()->create(['license_number' => $licenseNumber]);
    }

    public function test_multiple_doctor_profiles_can_have_null_license_numbers(): void
    {
        $firstProfile = User::factory()->create()->doctorProfile()->create();
        $secondProfile = User::factory()->create()->doctorProfile()->create();

        $this->assertNull($firstProfile->license_number);
        $this->assertNull($secondProfile->license_number);
    }

    public function test_doctor_profile_rejects_a_nonexistent_user(): void
    {
        $this->expectException(QueryException::class);

        DB::table('doctor_profiles')->insert([
            'user_id' => PHP_INT_MAX,
            'status' => 'active',
        ]);
    }

    public function test_deleting_a_user_with_a_doctor_profile_is_restricted(): void
    {
        $user = User::factory()->create();
        $user->doctorProfile()->create();

        $this->expectException(QueryException::class);

        $user->delete();
    }

    public function test_status_defaults_to_active_and_nullable_fields_accept_null(): void
    {
        $profile = User::factory()->create()->doctorProfile()->create([
            'specialization' => null,
            'license_number' => null,
            'bio' => null,
            'photo' => null,
        ]);
        $profile->refresh();

        $this->assertSame('active', $profile->status);
        $this->assertNull($profile->specialization);
        $this->assertNull($profile->license_number);
        $this->assertNull($profile->bio);
        $this->assertNull($profile->photo);
    }
}
