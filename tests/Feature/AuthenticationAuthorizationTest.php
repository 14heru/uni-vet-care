<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_IP = '203.0.113.10';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth', 'role:customer'])->get('/_test/roles/customer', fn () => response('customer'));
        Route::middleware(['auth', 'role:admin'])->get('/_test/roles/admin', fn () => response('admin'));
        Route::middleware(['auth', 'role:doctor'])->get('/_test/roles/doctor', fn () => response('doctor'));
        Route::middleware(['auth', 'role:owner'])->get('/_test/roles/owner', fn () => response('owner'));
        Route::middleware(['auth', 'role:admin,owner'])->get('/_test/roles/admin-or-owner', fn () => response('admin-or-owner'));
        Route::middleware(['auth', 'role:Admin'])->get('/_test/roles/invalid-case', fn () => response('invalid-case'));
    }

    public function test_valid_login_authenticates_the_user_and_regenerates_the_session(): void
    {
        $user = $this->createUser('customer', 'valid-login@example.test');
        $this->withSession(['pre_login_value' => 'preserved']);
        $oldSessionId = $this->app['session']->getId();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSessionId, $this->app['session']->getId());
        $this->assertSame('preserved', session('pre_login_value'));

        $audit = AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGIN)->sole();
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame(AuditLogService::ENTITY_USER, $audit->entity_type);
        $this->assertSame($user->id, $audit->entity_id);
        $this->assertNull($audit->metadata);
    }

    public function test_invalid_password_returns_generic_authentication_error(): void
    {
        $user = $this->createUser('customer', 'invalid-password@example.test');

        $response = $this->from('/')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/')
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest('web');
        $this->assertSame(0, AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGIN)->count());
    }

    public function test_inactive_user_cannot_log_in_and_receives_the_generic_error(): void
    {
        $user = $this->createUser('customer', 'inactive-login@example.test', 'inactive');

        $response = $this->from('/')->post('/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertRedirect('/')
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest('web');
        $this->assertSame(0, AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGIN)->count());
    }

    public function test_login_rate_limits_after_five_failed_attempts_with_a_generic_error(): void
    {
        $email = 'throttled-login@example.test';
        $throttleKey = $this->loginThrottleKey($email);
        RateLimiter::clear($throttleKey);

        try {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $response = $this->withServerVariables(['REMOTE_ADDR' => self::TEST_IP])
                    ->from('/')
                    ->post('/login', [
                        'email' => $email,
                        'password' => 'wrong-password',
                    ]);

                $response->assertRedirect('/')
                    ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
                $this->assertGuest('web');
            }

            $this->assertSame(5, RateLimiter::attempts($throttleKey));

            $sixthAttempt = $this->withServerVariables(['REMOTE_ADDR' => self::TEST_IP])
                ->from('/')
                ->post('/login', [
                    'email' => $email,
                    'password' => 'wrong-password',
                ]);

            $sixthAttempt->assertRedirect('/')
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
            $this->assertGuest('web');
            $this->assertSame(5, RateLimiter::attempts($throttleKey));
            $this->assertSame(0, AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGIN)->count());
        } finally {
            RateLimiter::clear($throttleKey);
        }
    }

    public function test_successful_login_clears_failed_attempts_before_later_failures(): void
    {
        $email = 'reset-login@example.test';
        $throttleKey = $this->loginThrottleKey($email);
        RateLimiter::clear($throttleKey);
        $user = $this->createUser('customer', $email);

        try {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $this->withServerVariables(['REMOTE_ADDR' => self::TEST_IP])
                    ->from('/')
                    ->post('/login', [
                        'email' => $email,
                        'password' => 'wrong-password',
                    ])
                    ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
            }

            $this->assertSame(3, RateLimiter::attempts($throttleKey));

            $this->withServerVariables(['REMOTE_ADDR' => self::TEST_IP])
                ->post('/login', [
                    'email' => $email,
                    'password' => 'correct-password',
                ])
                ->assertRedirect('/');

            $this->assertAuthenticatedAs($user, 'web');
            $this->assertSame(0, RateLimiter::attempts($throttleKey));

            auth('web')->logout();

            $this->withServerVariables(['REMOTE_ADDR' => self::TEST_IP])
                ->from('/')
                ->post('/login', [
                    'email' => $email,
                    'password' => 'wrong-password',
                ])
                ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

            $this->assertGuest('web');
            $this->assertSame(1, RateLimiter::attempts($throttleKey));
        } finally {
            RateLimiter::clear($throttleKey);
        }
    }

    public function test_logout_ends_authentication_and_invalidates_the_session(): void
    {
        $user = $this->createUser('customer', 'logout@example.test');
        $this->actingAs($user, 'web')->withSession(['existing_value' => 'present']);
        $oldSessionId = $this->app['session']->getId();
        $oldToken = session()->token();

        $response = $this->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest('web');
        $this->assertNotSame($oldSessionId, $this->app['session']->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertNull(session('existing_value'));

        $audit = AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGOUT)->sole();
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame(AuditLogService::ENTITY_USER, $audit->entity_type);
        $this->assertSame($user->id, $audit->entity_id);
        $this->assertNull($audit->metadata);
    }

    public function test_unauthenticated_logout_creates_no_audit_event(): void
    {
        $this->post('/logout')->assertRedirect('/login');

        $this->assertSame(0, AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGOUT)->count());
    }

    public function test_login_audit_failure_invalidates_session_and_leaves_user_unauthenticated(): void
    {
        $user = $this->createUser('customer', 'audit-failed-login@example.test');
        $this->withSession(['pre_login_value' => 'must be cleared']);
        $oldToken = session()->token();
        $failure = new QueryException('mysql', 'insert into audit_logs', [], new \Exception('Simulated audit persistence failure'));

        AuditLog::saving(function () use ($failure): void {
            throw $failure;
        });

        try {
            $response = $this->post('/login', [
                'email' => $user->email,
                'password' => 'correct-password',
            ]);
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }

        $response->assertStatus(500)
            ->assertSeeText('Unable to complete authentication. Please try again.')
            ->assertDontSee('Simulated audit persistence failure')
            ->assertDontSee('insert into audit_logs');
        $this->assertGuest('web');
        $this->assertNull(session('pre_login_value'));
        $this->assertNotSame($oldToken, session()->token());
        $this->assertSame(0, AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGIN)->count());
    }

    public function test_logout_audit_failure_leaves_user_logged_out(): void
    {
        $user = $this->createUser('customer', 'audit-failed-logout@example.test');
        $this->actingAs($user, 'web')->withSession(['existing_value' => 'must be cleared']);
        $oldToken = session()->token();
        $failure = new QueryException('mysql', 'insert into audit_logs', [], new \Exception('Simulated audit persistence failure'));

        AuditLog::saving(function () use ($failure): void {
            throw $failure;
        });

        try {
            $response = $this->post('/logout');
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }

        $response->assertStatus(500)
            ->assertSeeText('Unable to complete authentication. Please try again.')
            ->assertDontSee('Simulated audit persistence failure')
            ->assertDontSee('insert into audit_logs');
        $this->assertGuest('web');
        $this->assertNull(session('existing_value'));
        $this->assertNotSame($oldToken, session()->token());
        $this->assertSame(0, AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGOUT)->count());
    }

    public function test_unauthenticated_user_is_rejected_by_auth_middleware(): void
    {
        Route::middleware('auth')->get('/_test/authenticated-only', fn () => response('secret'));

        $this->get('/_test/authenticated-only')->assertRedirect('/login');
    }

    public function test_customer_passes_customer_role_middleware(): void
    {
        $this->actingAs($this->createUser('customer'))
            ->get('/_test/roles/customer')
            ->assertOk()
            ->assertSeeText('customer');
    }

    public function test_customer_is_forbidden_from_admin_role_middleware(): void
    {
        $this->actingAs($this->createUser('customer'))
            ->get('/_test/roles/admin')
            ->assertForbidden();
    }

    public function test_admin_passes_admin_role_middleware(): void
    {
        $this->actingAs($this->createUser('admin'))
            ->get('/_test/roles/admin')
            ->assertOk();
    }

    public function test_doctor_passes_doctor_role_middleware(): void
    {
        $this->actingAs($this->createUser('doctor'))
            ->get('/_test/roles/doctor')
            ->assertOk();
    }

    public function test_owner_passes_owner_role_middleware(): void
    {
        $this->actingAs($this->createUser('owner'))
            ->get('/_test/roles/owner')
            ->assertOk();
    }

    public function test_role_middleware_accepts_multiple_canonical_roles(): void
    {
        $this->actingAs($this->createUser('admin'))
            ->get('/_test/roles/admin-or-owner')
            ->assertOk();
    }

    public function test_request_supplied_role_cannot_bypass_authorization(): void
    {
        $this->actingAs($this->createUser('customer'))
            ->get('/_test/roles/admin?role=admin')
            ->assertForbidden();
    }

    public function test_authenticated_user_without_a_loaded_role_is_forbidden(): void
    {
        $user = $this->createUser('customer');
        $user->setRelation('role', null);

        $this->actingAs($user)
            ->get('/_test/roles/customer')
            ->assertForbidden();
    }

    public function test_invalid_case_mismatched_role_requirement_fails_closed(): void
    {
        $this->actingAs($this->createUser('admin'))
            ->get('/_test/roles/invalid-case')
            ->assertForbidden();
    }

    private function createUser(string $roleName, ?string $email = null, string $status = 'active'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        return User::factory()->for($role)->create([
            'email' => $email ?? fake()->unique()->safeEmail(),
            'password' => Hash::make('correct-password'),
            'status' => $status,
        ]);
    }

    private function loginThrottleKey(string $email): string
    {
        return Str::transliterate(Str::lower($email).'|'.self::TEST_IP);
    }
}
