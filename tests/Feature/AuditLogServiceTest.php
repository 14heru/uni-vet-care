<?php

namespace Tests\Feature;

use App\Exceptions\InvalidAuditLogException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    use RefreshDatabase;
    private AuditLogService $service;
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AuditLogService::class);
    }
    public function test_successful_audit_creation_with_authenticated_actor(): void
    {
        $user = $this->createUser();
        $log = $this->service->log($user, AuditLogService::ACTION_BOOKING_CREATED, AuditLogService::ENTITY_BOOKING, 1, 'Booking created', ['booking_code' => 'UVC-20260101-ABC', 'doctor_id' => 2]);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'actor_user_id' => $user->getKey()]);
        $this->assertTrue($log->actor->is($user));
    }
    public function test_successful_system_audit_with_null_actor(): void
    {
        $log = $this->service->log(null, AuditLogService::ACTION_AUTH_LOGIN, AuditLogService::ENTITY_USER, null, 'System event');
        $this->assertNull($log->actor_user_id);
        $this->assertNull($log->actor);
    }
    public function test_actor_relationship_is_persisted_correctly(): void
    {
        $user = $this->createUser();
        $log = $this->service->log($user, AuditLogService::ACTION_AUTH_LOGOUT, AuditLogService::ENTITY_USER, $user->getKey());
        $this->assertTrue($log->fresh()->actor->is($user));
    }
    public function test_supported_entity_types_are_accepted(): void
    {
        foreach (AuditLogService::ENTITIES as $entity) {
            $log = $this->service->log(null, AuditLogService::ACTION_AUTH_LOGIN, $entity, null);
            $this->assertSame($entity, $log->entity_type);
        }
    }
    public function test_unsupported_entity_type_is_rejected(): void
    {
        $this->expectException(InvalidAuditLogException::class);
        $this->service->log(null, AuditLogService::ACTION_AUTH_LOGIN, 'system', null);
    }
    public function test_supported_action_names_are_accepted(): void
    {
        foreach (AuditLogService::ACTIONS as $action) {
            $log = $this->service->log(null, $action, AuditLogService::ENTITY_BOOKING, null);
            $this->assertSame($action, $log->action);
        }
    }
    public function test_unsupported_action_is_rejected(): void
    {
        $this->expectException(InvalidAuditLogException::class);
        $this->service->log(null, 'RANDOM_ACTION', AuditLogService::ENTITY_BOOKING, null);
    }
    public function test_nullable_entity_id_works(): void
    {
        $log = $this->service->log(null, AuditLogService::ACTION_AUTH_LOGIN, AuditLogService::ENTITY_USER, null);
        $this->assertNull($log->entity_id);
    }
    public function test_positive_entity_id_works(): void
    {
        $log = $this->service->log(null, AuditLogService::ACTION_BOOKING_CREATED, AuditLogService::ENTITY_BOOKING, 42);
        $this->assertSame(42, $log->entity_id);
    }
    public function test_invalid_non_positive_entity_id_is_rejected(): void
    {
        $this->expectException(InvalidAuditLogException::class);
        $this->service->log(null, AuditLogService::ACTION_BOOKING_CREATED, AuditLogService::ENTITY_BOOKING, 0);
    }
    public function test_negative_entity_id_is_rejected(): void
    {
        $this->expectException(InvalidAuditLogException::class);
        $this->service->log(null, AuditLogService::ACTION_BOOKING_CREATED, AuditLogService::ENTITY_BOOKING, -5);
    }
    public function test_description_is_persisted(): void
    {
        $log = $this->service->log(null, AuditLogService::ACTION_BOOKING_CANCELLED, AuditLogService::ENTITY_BOOKING, 1, 'Cancelled by customer');
        $this->assertSame('Cancelled by customer', $log->description);
    }
    public function test_allowed_metadata_fields_persist(): void
    {
        $metadata = ['old_status'=>'PENDING','new_status'=>'CONFIRMED','reason'=>'Admin confirmed','booking_code'=>'UVC-123','appointment_start'=>'2026-10-08 09:00:00','appointment_end'=>'2026-10-08 09:30:00','doctor_id'=>1,'customer_id'=>2,'pet_id'=>3,'doctor_schedule_id'=>4];
        $log = $this->service->log(null, AuditLogService::ACTION_BOOKING_STATUS_CHANGED, AuditLogService::ENTITY_BOOKING, 1, null, $metadata);
        $this->assertSame($metadata, $log->fresh()->metadata);
    }
    public function test_unknown_metadata_keys_are_excluded(): void
    {
        $log = $this->service->log(null, AuditLogService::ACTION_BOOKING_CREATED, AuditLogService::ENTITY_BOOKING, 1, null, ['booking_code'=>'UVC-1','unknown_field'=>'should be dropped','foo'=>'bar']);
        $this->assertArrayHasKey('booking_code', $log->metadata);
        $this->assertArrayNotHasKey('unknown_field', $log->metadata);
    }
    public function test_sensitive_metadata_keys_are_excluded(): void
    {
        $log = $this->service->log(null, AuditLogService::ACTION_AUTH_LOGIN, AuditLogService::ENTITY_USER, 1, null, ['booking_code'=>'UVC-1','password'=>'secret123','token'=>'abc','api_key'=>'key','secret'=>'s','payment_secret'=>'pay','session_id'=>'sess','cvv'=>'123','remember_token'=>'tok']);
        foreach (['password','token','api_key','secret','payment_secret','session_id','cvv','remember_token'] as $key) { $this->assertArrayNotHasKey($key, $log->metadata ?? []); }
        $this->assertArrayHasKey('booking_code', $log->metadata);
    }
    public function test_raw_model_serialization_is_not_accepted_as_metadata(): void
    {
        $user = $this->createUser();
        $log = $this->service->log(null, AuditLogService::ACTION_BOOKING_CREATED, AuditLogService::ENTITY_BOOKING, 1, null, ['booking_code'=>'UVC-1','doctor_id'=>$user]);
        $this->assertArrayNotHasKey('doctor_id', $log->metadata ?? []);
        $this->assertSame(['booking_code'=>'UVC-1'], $log->metadata);
    }
    public function test_audit_history_is_append_only_at_service_level(): void
    {
        $log1 = $this->service->log(null, AuditLogService::ACTION_BOOKING_CREATED, AuditLogService::ENTITY_BOOKING, 1);
        $log2 = $this->service->log(null, AuditLogService::ACTION_BOOKING_STATUS_CHANGED, AuditLogService::ENTITY_BOOKING, 1);
        $this->assertNotEquals($log1->getKey(), $log2->getKey());
        $this->assertFalse(method_exists($this->service, 'update'));
        $this->assertFalse(method_exists($this->service, 'delete'));
    }
    public function test_unexpected_db_failures_are_not_swallowed(): void
    {
        $failure = new QueryException('mysql', 'insert into audit_logs (action) values (?)', [], new \Exception('DB down'));
        AuditLog::saving(function () use ($failure) {
            throw $failure;
        });
        try {
            $this->service->log(null, AuditLogService::ACTION_BOOKING_CREATED, AuditLogService::ENTITY_BOOKING, 1);
            $this->fail('Expected database exception to bubble from AuditLogService::log().');
        } catch (QueryException $e) {
            $this->assertSame($failure, $e);
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }
    public function test_service_can_be_called_from_inside_existing_transaction(): void
    {
        $user = $this->createUser();
        DB::transaction(function () use ($user): void {
            $log = $this->service->log($user, AuditLogService::ACTION_USER_ROLE_CHANGED, AuditLogService::ENTITY_USER, $user->getKey(), null, ['reason'=>'promotion']);
            $this->assertTrue(AuditLog::query()->whereKey($log->getKey())->exists());
        });
        $this->assertDatabaseCount('audit_logs', 1);
    }
    public function test_transaction_atomicity_when_caller_wraps_audit_and_business_op(): void
    {
        $user = $this->createUser();
        try { DB::transaction(function () use ($user): void { $this->service->log($user, AuditLogService::ACTION_BOOKING_CREATED, AuditLogService::ENTITY_BOOKING, 99); throw new \RuntimeException('business failure'); }); } catch (\RuntimeException $e) {}
        $this->assertDatabaseCount('audit_logs', 0);
    }
    private function createUser(): User
    {
        $role = Role::firstOrCreate(['name'=>'customer']);
        return User::factory()->for($role)->create();
    }
}
