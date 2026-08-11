<?php

namespace App\Http\Services;

use App\Http\Exceptions\ReservationConflictException;
use App\Http\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function create(array $data, Request $request): array
    {
        return DB::transaction(function () use ($data, $request): array {
            $treatmentIds = collect($data['items'])->pluck('treatment_id')->map(fn ($id) => (int) $id)->unique()->values();
            $employeeIds = collect($data['items'])
                ->flatMap(fn (array $item) => collect($item['staff'])->pluck('employee_id'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->sort()
                ->values();

            $treatments = DB::table('treatments')
                ->whereIn('id', $treatmentIds)
                ->where('is_active', true)
                ->get()
                ->keyBy('id');

            if ($treatments->count() !== $treatmentIds->count()) {
                throw ValidationException::withMessages([
                    'items' => ['Semua treatment harus tersedia dan aktif.'],
                ]);
            }

            // Lock every selected employee in a stable order. Reservation writers that
            // involve the same employee are serialized, including when no schedule row exists yet.
            $employees = DB::table('employees')
                ->whereIn('id', $employeeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($employees->count() !== $employeeIds->count() || $employees->contains(
                fn (object $employee): bool => ! $employee->active || ! $employee->is_service_provider
            )) {
                throw ValidationException::withMessages([
                    'items' => ['Semua pegawai yang ditugaskan harus aktif sebagai penyedia layanan.'],
                ]);
            }

            $candidates = $this->buildCandidates($data, $treatments);
            $this->validateStaffAssignments($candidates);
            $this->authorizePriceOverrides($candidates, $request);
            [$conflicts, $conflictAssignments] = $this->findConflicts($candidates, $employees);

            $override = (bool) ($data['override_conflict'] ?? false);
            $canOverride = (bool) $request->user()?->can('reservations.override_conflict');

            if ($conflicts !== [] && ! $override) {
                throw new ReservationConflictException($conflicts, $canOverride);
            }

            if ($conflicts !== [] && (! $canOverride || trim((string) ($data['override_reason'] ?? '')) === '')) {
                throw ValidationException::withMessages([
                    'override_reason' => ['Override konflik memerlukan izin khusus dan alasan.'],
                ]);
            }

            $customerId = $this->upsertCustomer($data['name'], $data['phone']);
            $bookingCode = 'RSV-'.Str::upper((string) Str::ulid());
            $temporaryQueue = 'TMP-'.Str::upper(Str::random(12));
            $firstStart = collect($candidates)->min(fn (array $item) => $item['start']->getTimestamp());
            $reservationTime = CarbonImmutable::createFromTimestamp($firstStart, config('app.timezone'))->format('H:i:s');
            $now = now();

            $reservationId = DB::table('reservations')->insertGetId([
                'booking_code' => $bookingCode,
                'queue_number' => $temporaryQueue,
                'customer_id' => $customerId,
                'reservation_date' => $data['date'],
                'reservation_time' => $reservationTime,
                'source' => $data['source'],
                'status' => 'scheduled',
                'general_notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
                'updated_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // The primary key makes this display number concurrency-safe; it does not
            // depend on COUNT(*) or a read-then-increment sequence.
            $queueNumber = 'A'.str_pad((string) $reservationId, 6, '0', STR_PAD_LEFT);
            DB::table('reservations')->where('id', $reservationId)->update([
                'queue_number' => $queueNumber,
                'updated_at' => $now,
            ]);

            $createdItems = [];

            foreach ($candidates as $itemIndex => $candidate) {
                $treatment = $candidate['treatment'];
                $unitPrice = array_key_exists('actual_price', $candidate['input']) && $candidate['input']['actual_price'] !== null
                    ? (int) $candidate['input']['actual_price']
                    : (int) $treatment->normal_price;
                $commissionPercent = FixedPoint::normalizePercent((string) $treatment->default_commission_percent);
                $commissionAmount = FixedPoint::percentOf($unitPrice, $commissionPercent);

                $itemId = DB::table('reservation_items')->insertGetId([
                    'reservation_id' => $reservationId,
                    'treatment_id' => $treatment->id,
                    'treatment_name' => $treatment->name,
                    'duration_minutes' => $treatment->duration_minutes,
                    'normal_price' => $treatment->normal_price,
                    'unit_price' => $unitPrice,
                    'discount_percent' => FixedPoint::normalizePercent(0),
                    'discount_amount' => 0,
                    'net_price' => $unitPrice,
                    'commission_percent' => $commissionPercent,
                    'commission_amount' => $commissionAmount,
                    'scheduled_start_at' => $candidate['start'],
                    'scheduled_end_at' => $candidate['end'],
                    'work_status' => 'waiting',
                    'notes' => $candidate['input']['notes'] ?? null,
                    'sort_order' => $itemIndex,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($candidate['input']['staff'] as $staff) {
                    $employeeId = (int) $staff['employee_id'];
                    $assignmentKey = $itemIndex.':'.$employeeId;
                    $wasOverridden = isset($conflictAssignments[$assignmentKey]);
                    $isPrimary = $staff['role'] === 'primary';

                    DB::table('reservation_item_staff')->insert([
                        'reservation_item_id' => $itemId,
                        'employee_id' => $employeeId,
                        'role' => $staff['role'],
                        // Phase 1 snapshots the treatment default on the primary assignment.
                        // Future commission rules can explicitly split it without duplicating payout.
                        'commission_percent' => $isPrimary ? $commissionPercent : FixedPoint::normalizePercent(0),
                        'commission_amount' => $isPrimary ? $commissionAmount : 0,
                        'conflict_override_reason' => $wasOverridden ? $data['override_reason'] : null,
                        'conflict_overridden_by' => $wasOverridden ? $request->user()?->id : null,
                        'conflict_overridden_at' => $wasOverridden ? $now : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $createdItems[] = [
                    'id' => $itemId,
                    'treatment_id' => (int) $treatment->id,
                    'treatment_name' => $treatment->name,
                    'start_at' => $candidate['start']->toIso8601String(),
                    'end_at' => $candidate['end']->toIso8601String(),
                    'work_status' => 'waiting',
                ];
            }

            $this->logger->log(
                $request,
                'reservation.created',
                'reservation',
                $reservationId,
                "Membuat reservasi {$bookingCode} untuk {$data['name']}",
                [
                    'booking_code' => $bookingCode,
                    'item_count' => count($createdItems),
                    'employee_ids' => $employeeIds->all(),
                    'price_overrides' => collect($candidates)
                        ->map(function (array $candidate, int $index): ?array {
                            $actualPrice = $candidate['input']['actual_price'] ?? null;

                            if ($actualPrice === null || (int) $actualPrice === (int) $candidate['treatment']->normal_price) {
                                return null;
                            }

                            return [
                                'item_index' => $index,
                                'treatment_id' => (int) $candidate['treatment']->id,
                                'normal_price' => (int) $candidate['treatment']->normal_price,
                                'actual_price' => (int) $actualPrice,
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all(),
                    'conflict_overridden' => $conflicts !== [],
                    'override_reason' => $conflicts !== [] ? $data['override_reason'] : null,
                    'conflicts' => $conflicts,
                ],
            );

            return [
                'id' => $reservationId,
                'booking_code' => $bookingCode,
                'queue_number' => $queueNumber,
                'status' => 'scheduled',
                'items' => $createdItems,
            ];
        }, 3);
    }

    public function updateHeaderStatus(int $reservationId, string $status, ?string $reason, Request $request): array
    {
        return DB::transaction(function () use ($reservationId, $status, $reason, $request): array {
            $reservation = DB::table('reservations')->where('id', $reservationId)->lockForUpdate()->first();

            abort_unless($reservation, 404, 'Reservasi tidak ditemukan.');
            if ($status === 'cancelled') {
                $this->ensureNotPaid($reservationId);
            }

            if ($reservation->status === $status) {
                return ['id' => $reservationId, 'status' => $status, 'idempotent_replay' => true];
            }

            $transitions = [
                'scheduled' => ['arrived', 'cancelled'],
                'arrived' => ['cancelled'],
                'in_service' => ['cancelled'],
                'completed' => [],
                'cancelled' => [],
            ];

            if (! in_array($status, $transitions[$reservation->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Status tidak dapat diubah dari {$reservation->status} menjadi {$status}."],
                ]);
            }

            $now = now();

            if ($status === 'completed') {
                $unfinished = DB::table('reservation_items')
                    ->where('reservation_id', $reservationId)
                    ->whereNotIn('work_status', ['finished', 'cancelled'])
                    ->exists();

                if ($unfinished) {
                    throw ValidationException::withMessages([
                        'status' => ['Reservasi hanya dapat diselesaikan setelah semua item selesai atau dibatalkan.'],
                    ]);
                }
            }

            if ($status === 'cancelled') {
                $hasFinishedItem = DB::table('reservation_items')
                    ->where('reservation_id', $reservationId)
                    ->where('work_status', 'finished')
                    ->exists();

                if ($hasFinishedItem) {
                    throw ValidationException::withMessages([
                        'status' => ['Reservasi dengan layanan yang sudah selesai tidak boleh dibatalkan seluruhnya. Batalkan hanya item yang belum dikerjakan.'],
                    ]);
                }

                DB::table('reservation_items')
                    ->where('reservation_id', $reservationId)
                    ->whereNotIn('work_status', ['finished', 'cancelled'])
                    ->update(['work_status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);
            }

            $updates = [
                'status' => $status,
                'updated_by' => $request->user()?->id,
                'updated_at' => $now,
            ];

            if ($status === 'cancelled') {
                $updates += [
                    'cancelled_by' => $request->user()?->id,
                    'cancelled_at' => $now,
                    'cancellation_reason' => $reason ?: 'Dibatalkan dari status reservasi',
                ];
            }

            DB::table('reservations')->where('id', $reservationId)->update($updates);
            $this->logger->log(
                $request,
                'reservation.status_changed',
                'reservation',
                $reservationId,
                "Mengubah status reservasi dari {$reservation->status} menjadi {$status}",
                ['from' => $reservation->status, 'to' => $status, 'reason' => $reason],
            );

            return ['id' => $reservationId, 'status' => $status, 'idempotent_replay' => false];
        }, 3);
    }

    public function updateItemStatus(
        int $reservationId,
        int $itemId,
        string $status,
        ?string $reason,
        Request $request,
    ): array {
        return DB::transaction(function () use ($reservationId, $itemId, $status, $reason, $request): array {
            $reservation = DB::table('reservations')->where('id', $reservationId)->lockForUpdate()->first();
            abort_unless($reservation, 404, 'Reservasi tidak ditemukan.');
            abort_if($reservation->status === 'cancelled', 422, 'Reservasi yang dibatalkan tidak dapat diubah.');
            if ($status === 'cancelled') {
                $this->ensureNotPaid($reservationId);
            }

            $item = DB::table('reservation_items')
                ->where('id', $itemId)
                ->where('reservation_id', $reservationId)
                ->lockForUpdate()
                ->first();
            abort_unless($item, 404, 'Item reservasi tidak ditemukan.');

            if ($item->work_status === $status) {
                return [
                    'id' => $itemId,
                    'reservation_id' => $reservationId,
                    'work_status' => $status,
                    'reservation_status' => $reservation->status,
                    'idempotent_replay' => true,
                ];
            }

            $transitions = [
                'waiting' => ['in_progress', 'cancelled'],
                'in_progress' => ['continue', 'ready', 'finished', 'overtime', 'cancelled'],
                'continue' => ['in_progress', 'ready', 'finished', 'overtime', 'cancelled'],
                'ready' => ['in_progress', 'finished', 'overtime', 'cancelled'],
                'overtime' => ['continue', 'ready', 'finished', 'cancelled'],
                'finished' => [],
                'cancelled' => [],
            ];

            if (! in_array($status, $transitions[$item->work_status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Status item tidak dapat diubah dari {$item->work_status} menjadi {$status}."],
                ]);
            }

            $now = now();
            $timestampColumns = [
                'in_progress' => 'started_at',
                'continue' => 'continued_at',
                'ready' => 'ready_at',
                'overtime' => 'overtime_at',
                'finished' => 'finished_at',
                'cancelled' => 'cancelled_at',
            ];
            $updates = ['work_status' => $status, 'updated_at' => $now];

            if (isset($timestampColumns[$status])) {
                $column = $timestampColumns[$status];
                if ($column !== 'started_at' || $item->started_at === null) {
                    $updates[$column] = $now;
                }
            }

            DB::table('reservation_items')->where('id', $itemId)->update($updates);
            $reservationStatus = $this->syncHeaderStatus($reservationId, $request->user()?->id, $reason);
            $this->logger->log(
                $request,
                'reservation_item.status_changed',
                'reservation_item',
                $itemId,
                "Mengubah status item reservasi dari {$item->work_status} menjadi {$status}",
                [
                    'reservation_id' => $reservationId,
                    'from' => $item->work_status,
                    'to' => $status,
                    'reason' => $reason,
                ],
            );

            return [
                'id' => $itemId,
                'reservation_id' => $reservationId,
                'work_status' => $status,
                'reservation_status' => $reservationStatus,
                'idempotent_replay' => false,
            ];
        }, 3);
    }

    public function availability(string $date, string $time, int $treatmentId): array
    {
        $treatment = DB::table('treatments')->where('id', $treatmentId)->where('is_active', true)->first();
        abort_unless($treatment, 404, 'Treatment tidak ditemukan atau tidak aktif.');

        $start = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", config('app.timezone'));
        $end = $start->addMinutes((int) $treatment->duration_minutes);

        return DB::table('employees')
            ->where('active', true)
            ->where('is_service_provider', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'position', 'specialty'])
            ->map(function (object $employee) use ($start, $end): array {
                $conflicts = $this->existingConflicts((int) $employee->id, $start, $end);

                return [
                    'id' => (int) $employee->id,
                    'code' => $employee->code,
                    'name' => $employee->name,
                    'position' => $employee->position,
                    'specialty' => $employee->specialty,
                    'available' => $conflicts->isEmpty(),
                    'conflicts' => $conflicts->map(fn (object $row): array => [
                        'reservation_id' => (int) $row->reservation_id,
                        'reservation_item_id' => (int) $row->reservation_item_id,
                        'booking_code' => $row->booking_code,
                        'start_at' => $row->scheduled_start_at,
                        'end_at' => $row->scheduled_end_at,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    private function buildCandidates(array $data, Collection $treatments): array
    {
        return collect($data['items'])->map(function (array $item) use ($data, $treatments): array {
            $treatment = $treatments->get((int) $item['treatment_id']);
            $start = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i',
                "{$data['date']} {$item['start_time']}",
                config('app.timezone'),
            );

            return [
                'input' => $item,
                'treatment' => $treatment,
                'start' => $start,
                'end' => $start->addMinutes((int) $treatment->duration_minutes),
            ];
        })->all();
    }

    private function validateStaffAssignments(array $candidates): void
    {
        foreach ($candidates as $itemIndex => $candidate) {
            $staff = collect($candidate['input']['staff']);
            $employeeIds = $staff->pluck('employee_id')->map(fn ($id) => (int) $id);

            if ($employeeIds->unique()->count() !== $employeeIds->count()) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.staff" => ['Pegawai yang sama tidak boleh ditambahkan dua kali pada satu item.'],
                ]);
            }

            if ($staff->where('role', 'primary')->count() !== 1) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.staff" => ['Setiap item harus memiliki tepat satu pegawai primary.'],
                ]);
            }
        }
    }

    private function authorizePriceOverrides(array $candidates, Request $request): void
    {
        foreach ($candidates as $itemIndex => $candidate) {
            $actualPrice = $candidate['input']['actual_price'] ?? null;

            if ($actualPrice === null || (int) $actualPrice === (int) $candidate['treatment']->normal_price) {
                continue;
            }

            if (! $request->user()?->can('reservations.override_price')) {
                throw ValidationException::withMessages([
                    "items.{$itemIndex}.actual_price" => ['Anda tidak memiliki izin untuk mengubah harga normal treatment.'],
                ]);
            }
        }
    }

    private function findConflicts(array $candidates, Collection $employees): array
    {
        $conflicts = [];
        $assignments = [];

        foreach ($candidates as $itemIndex => $candidate) {
            foreach ($candidate['input']['staff'] as $staff) {
                $employeeId = (int) $staff['employee_id'];
                $employee = $employees->get($employeeId);

                foreach ($this->existingConflicts($employeeId, $candidate['start'], $candidate['end'], true) as $existing) {
                    $conflicts[] = [
                        'type' => 'existing_reservation',
                        'item_index' => $itemIndex,
                        'employee_id' => $employeeId,
                        'employee_name' => $employee->name,
                        'requested_start_at' => $candidate['start']->toIso8601String(),
                        'requested_end_at' => $candidate['end']->toIso8601String(),
                        'reservation_id' => (int) $existing->reservation_id,
                        'reservation_item_id' => (int) $existing->reservation_item_id,
                        'booking_code' => $existing->booking_code,
                        'conflicting_start_at' => $existing->scheduled_start_at,
                        'conflicting_end_at' => $existing->scheduled_end_at,
                    ];
                    $assignments[$itemIndex.':'.$employeeId] = true;
                }

                foreach ($candidates as $otherIndex => $other) {
                    if ($otherIndex >= $itemIndex || ! collect($other['input']['staff'])->contains(
                        fn (array $otherStaff): bool => (int) $otherStaff['employee_id'] === $employeeId
                    )) {
                        continue;
                    }

                    if ($other['start']->lt($candidate['end']) && $other['end']->gt($candidate['start'])) {
                        $conflicts[] = [
                            'type' => 'request_item',
                            'item_index' => $itemIndex,
                            'conflicting_item_index' => $otherIndex,
                            'employee_id' => $employeeId,
                            'employee_name' => $employee->name,
                            'requested_start_at' => $candidate['start']->toIso8601String(),
                            'requested_end_at' => $candidate['end']->toIso8601String(),
                            'conflicting_start_at' => $other['start']->toIso8601String(),
                            'conflicting_end_at' => $other['end']->toIso8601String(),
                        ];
                        $assignments[$itemIndex.':'.$employeeId] = true;
                        $assignments[$otherIndex.':'.$employeeId] = true;
                    }
                }
            }
        }

        return [$conflicts, $assignments];
    }

    private function existingConflicts(
        int $employeeId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $lockForUpdate = false,
    ): Collection {
        $query = DB::table('reservation_item_staff as staff')
            ->join('reservation_items as item', 'item.id', '=', 'staff.reservation_item_id')
            ->join('reservations as reservation', 'reservation.id', '=', 'item.reservation_id')
            ->where('staff.employee_id', $employeeId)
            ->whereNotIn('reservation.status', ['cancelled', 'completed'])
            ->whereNotIn('item.work_status', ['cancelled', 'finished'])
            ->where('item.scheduled_start_at', '<', $end)
            ->where('item.scheduled_end_at', '>', $start)
            ->orderBy('item.scheduled_start_at');

        if ($lockForUpdate) {
            // A locking read is a current read in InnoDB. This is required even
            // after the employee lock because an earlier consistent read may have
            // established a REPEATABLE READ snapshot before another writer committed.
            $query->lockForUpdate();
        }

        return $query->get([
            'reservation.id as reservation_id',
            'reservation.booking_code',
            'item.id as reservation_item_id',
            'item.scheduled_start_at',
            'item.scheduled_end_at',
        ]);
    }

    private function upsertCustomer(string $name, string $phone): int
    {
        $now = now();
        DB::table('customers')->upsert([[
            'code' => 'CUS-'.Str::upper((string) Str::ulid()),
            'name' => $name,
            'phone' => $phone,
            'is_member' => false,
            'visit_count' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['phone'], ['name', 'is_active', 'updated_at']);

        return (int) DB::table('customers')->where('phone', $phone)->value('id');
    }

    private function ensureNotPaid(int $reservationId): void
    {
        abort_if(
            DB::table('transactions')->where('reservation_id', $reservationId)->where('status', 'paid')->exists(),
            422,
            'Reservasi yang sudah dibayar tidak dapat diubah.',
        );
    }

    private function syncHeaderStatus(int $reservationId, ?int $userId, ?string $reason): string
    {
        $statuses = DB::table('reservation_items')->where('reservation_id', $reservationId)->pluck('work_status');
        $reservation = DB::table('reservations')->where('id', $reservationId)->first();
        $now = now();

        if ($statuses->every(fn (string $status): bool => $status === 'cancelled')) {
            DB::table('reservations')->where('id', $reservationId)->update([
                'status' => 'cancelled',
                'updated_by' => $userId,
                'cancelled_by' => $userId,
                'cancelled_at' => $now,
                'cancellation_reason' => $reason ?: 'Semua item reservasi dibatalkan',
                'updated_at' => $now,
            ]);

            return 'cancelled';
        }

        $active = $statuses->reject(fn (string $status): bool => $status === 'cancelled');
        $status = $active->every(fn (string $itemStatus): bool => $itemStatus === 'finished')
            ? 'completed'
            : ($active->contains(fn (string $itemStatus): bool => $itemStatus !== 'waiting')
                ? 'in_service'
                : ($reservation->status === 'arrived' ? 'arrived' : 'scheduled'));

        DB::table('reservations')->where('id', $reservationId)->update([
            'status' => $status,
            'updated_by' => $userId,
            'updated_at' => $now,
        ]);

        if (
            $status === 'completed'
            && $reservation->status !== 'completed'
            && DB::table('transactions')->where('reservation_id', $reservationId)->where('status', 'paid')->exists()
        ) {
            DB::table('customers')->where('id', $reservation->customer_id)->increment('visit_count');
        }

        return $status;
    }
}
