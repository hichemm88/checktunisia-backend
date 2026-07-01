<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\AuthoritySearchLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    private static string $requestId = '';

    public static function getRequestId(): string
    {
        if (empty(static::$requestId)) {
            static::$requestId = (string) Str::uuid();
        }
        return static::$requestId;
    }

    public static function log(
        string $action,
        ?Model $subject = null,
        array $oldValues = [],
        array $newValues = [],
        ?User $actor = null,
        ?string $hotelId = null,
    ): AuditLog {
        /** @var User|null $user */
        $user  = $actor ?? Auth::user();
        $hotel = $hotelId ?? ($user?->isHotelStaff() ? $user->hotel()?->id : null);

        return AuditLog::create([
            'request_id'   => static::getRequestId(),
            'actor_id'     => $user?->id,
            'actor_role'   => $user?->primary_role,
            'hotel_id'     => $hotel,
            'action'       => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id'   => $subject?->getKey(),
            'old_values'   => empty($oldValues) ? null : $oldValues,
            'new_values'   => empty($newValues) ? null : $newValues,
            'ip_address'   => Request::ip(),
            'user_agent'   => Request::userAgent(),
            'created_at'   => now(),
        ]);
    }

    public static function logAuthoritySearch(
        array $searchParams,
        int $resultCount,
        int $executionTimeMs,
        ?int $organizationId = null,
    ): AuditLog {
        /** @var User $user */
        $user = Auth::user();

        $auditLog = static::log(
            action: 'authority.search',
            newValues: ['search_params' => $searchParams, 'result_count' => $resultCount],
        );

        AuthoritySearchLog::create([
            'audit_log_id'       => $auditLog->id,
            'user_id'            => $user->id,
            'organization_id'    => $organizationId ?? $user->authorityProfile?->organization_id,
            'search_params'      => $searchParams,
            'result_count'       => $resultCount,
            'execution_time_ms'  => $executionTimeMs,
            'created_at'         => now(),
        ]);

        return $auditLog;
    }

    public static function logAuthorityView(string $guestId): AuditLog
    {
        return static::log(
            action: 'authority.guest_viewed',
            newValues: ['guest_id' => $guestId],
        );
    }
}
