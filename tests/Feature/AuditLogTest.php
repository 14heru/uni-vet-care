<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_can_be_system_generated_without_an_actor(): void
    {
        $log = AuditLog::query()->create([
            'action' => 'booking.created',
            'entity_type' => 'booking',
            'entity_id' => null,
            'description' => null,
            'metadata' => null,
        ]);

        $this->assertNull($log->actor_user_id);
        $this->assertNull($log->actor);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id, 'actor_user_id' => null]);
    }

    public function test_audit_log_belongs_to_actor_and_user_has_many_audit_logs(): void
    {
        $user = $this->createUser();
        $log = $user->auditLogs()->create([
            'action' => 'booking.confirmed',
            'entity_type' => 'booking',
            'entity_id' => 123,
        ]);

        $this->assertTrue($log->actor->is($user));
        $this->assertTrue($user->auditLogs()->whereKey($log->getKey())->exists());
    }

    public function test_nonexistent_actor_user_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('audit_logs')->insert([
            'actor_user_id' => PHP_INT_MAX,
            'action' => 'booking.created',
            'entity_type' => 'booking',
            'entity_id' => null,
            'description' => null,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_actor_user_with_audit_logs_is_restricted(): void
    {
        $user = $this->createUser();
        $user->auditLogs()->create([
            'action' => 'booking.created',
            'entity_type' => 'booking',
        ]);

        $this->expectException(QueryException::class);

        $user->delete();
    }

    public function test_action_and_entity_fields_are_stored_with_optional_entity_id_and_description(): void
    {
        $log = AuditLog::query()->create([
            'action' => 'booking.status_changed',
            'entity_type' => 'booking',
            'entity_id' => 42,
            'description' => 'Booking confirmed by clinic staff.',
        ]);
        $systemLog = AuditLog::query()->create([
            'action' => 'system.maintenance',
            'entity_type' => 'system',
            'entity_id' => null,
            'description' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'booking.status_changed',
            'entity_type' => 'booking',
            'entity_id' => 42,
            'description' => 'Booking confirmed by clinic staff.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'id' => $systemLog->id,
            'entity_type' => 'system',
            'entity_id' => null,
            'description' => null,
        ]);
    }

    public function test_json_metadata_persists_and_round_trips_as_an_array(): void
    {
        $metadata = ['old_status' => 'PENDING', 'new_status' => 'CONFIRMED', 'source' => 'admin'];
        $log = AuditLog::query()->create([
            'action' => 'booking.status_changed',
            'entity_type' => 'booking',
            'entity_id' => 42,
            'metadata' => $metadata,
        ]);

        $this->assertSame($metadata, $log->fresh()->metadata);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_audit_log_timestamps_are_persisted(): void
    {
        $log = AuditLog::query()->create([
            'action' => 'booking.created',
            'entity_type' => 'booking',
        ]);

        $this->assertNotNull($log->fresh()->created_at);
        $this->assertNotNull($log->fresh()->updated_at);
    }

    public function test_audit_logs_have_actor_and_entity_lookup_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('audit_logs'))->keyBy('name');

        $this->assertSame(['actor_user_id'], $indexes['audit_logs_actor_user_id_index']['columns']);
        $this->assertSame(['entity_type', 'entity_id'], $indexes['audit_logs_entity_lookup']['columns']);
    }

    public function test_audit_logs_preserve_history_without_soft_deletes_or_credential_columns(): void
    {
        $this->assertFalse(Schema::hasColumn('audit_logs', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'password'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'token'));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'payment_secret'));
    }

    private function createUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'admin']);

        return User::factory()->for($role)->create();
    }
}
