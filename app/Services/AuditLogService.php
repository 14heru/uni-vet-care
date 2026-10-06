<?php

namespace App\Services;

use App\Exceptions\InvalidAuditLogException;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    public const ACTION_AUTH_LOGIN = 'AUTH_LOGIN';
    public const ACTION_AUTH_LOGOUT = 'AUTH_LOGOUT';
    public const ACTION_BOOKING_CREATED = 'BOOKING_CREATED';
    public const ACTION_BOOKING_STATUS_CHANGED = 'BOOKING_STATUS_CHANGED';
    public const ACTION_BOOKING_CANCELLED = 'BOOKING_CANCELLED';
    public const ACTION_USER_ROLE_CHANGED = 'USER_ROLE_CHANGED';
    public const ACTIONS = [self::ACTION_AUTH_LOGIN,self::ACTION_AUTH_LOGOUT,self::ACTION_BOOKING_CREATED,self::ACTION_BOOKING_STATUS_CHANGED,self::ACTION_BOOKING_CANCELLED,self::ACTION_USER_ROLE_CHANGED];
    public const ENTITY_BOOKING = 'booking';
    public const ENTITY_USER = 'user';
    public const ENTITY_PET = 'pet';
    public const ENTITY_DOCTOR_PROFILE = 'doctor_profile';
    public const ENTITY_CUSTOMER_PROFILE = 'customer_profile';
    public const ENTITY_DOCTOR_SCHEDULE = 'doctor_schedule';
    public const ENTITY_SCHEDULE_EXCEPTION = 'schedule_exception';
    public const ENTITIES = [self::ENTITY_BOOKING,self::ENTITY_USER,self::ENTITY_PET,self::ENTITY_DOCTOR_PROFILE,self::ENTITY_CUSTOMER_PROFILE,self::ENTITY_DOCTOR_SCHEDULE,self::ENTITY_SCHEDULE_EXCEPTION];
    private const ALLOWED_METADATA_KEYS = ['old_status','new_status','reason','booking_code','appointment_start','appointment_end','doctor_id','customer_id','pet_id','doctor_schedule_id'];
    private const SENSITIVE_METADATA_KEYS = ['password','password_confirmation','token','access_token','refresh_token','remember_token','session','session_id','api_key','secret','payment_secret','card','card_number','card_data','cvv','raw_payload','raw_headers'];
    public function log(?User $actor, string $action, string $entityType, ?int $entityId = null, ?string $description = null, ?array $metadata = null): AuditLog
    {
        $a = strtoupper(trim($action));
        if (!in_array($a, self::ACTIONS, true)) throw InvalidAuditLogException::invalidAction($action);
        $e = strtolower(trim($entityType));
        if (!in_array($e, self::ENTITIES, true)) throw InvalidAuditLogException::invalidEntityType($entityType);
        if ($entityId !== null && $entityId <= 0) throw InvalidAuditLogException::invalidEntityId();
        $m = $this->sanitizeMetadata($metadata);
        $audit = new AuditLog(['action'=>$a,'entity_type'=>$e,'entity_id'=>$entityId,'description'=>$description!==null?trim($description):null,'metadata'=>$m]);
        if ($actor !== null) { if (!$actor->exists || $actor->getKey()===null) throw InvalidAuditLogException::invalidMetadata(); $audit->actor()->associate($actor); }
        $audit->save(); return $audit;
    }
    private function sanitizeMetadata(?array $metadata): ?array
    {
        if ($metadata===null) return null;
        if ($metadata===[]) return null;
        $result=[];
        foreach ($metadata as $key=>$value) {
            if (!is_string($key)) continue;
            $nk=strtolower(trim($key));
            if (in_array($nk,self::SENSITIVE_METADATA_KEYS,true)) continue;
            if (!in_array($nk,self::ALLOWED_METADATA_KEYS,true)) continue;
            if ($value instanceof Model || is_object($value) || is_resource($value)) continue;
            if (is_array($value)) { $san=$this->sanitizeMetadataValue($value); if ($san===null) continue; $result[$nk]=$san; continue; }
            if (!is_scalar($value) && $value!==null) continue;
            if (is_string($value)) $value=trim($value);
            if (is_float($value) && !is_finite($value)) continue;
            $result[$nk]=$value;
        }
        return $result===[]?null:$result;
    }
    private function sanitizeMetadataValue(array $value): ?array
    {
        $out=[]; foreach ($value as $k=>$v) {
            if (is_object($v) || is_resource($v)) return null;
            if (is_array($v)) { $n=$this->sanitizeMetadataValue($v); if ($n===null) return null; $out[$k]=$n; continue; }
            if (!is_scalar($v) && $v!==null) return null;
            if (is_float($v) && !is_finite($v)) return null;
            $out[$k]=$v;
        } return $out;
    }
}
