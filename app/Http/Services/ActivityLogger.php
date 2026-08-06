<?php

namespace App\Http\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityLogger
{
    public function log(
        Request $request,
        string $action,
        string $subjectType,
        ?int $subjectId,
        string $description,
        array $metadata = [],
    ): void {
        DB::table('activity_logs')->insert([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
