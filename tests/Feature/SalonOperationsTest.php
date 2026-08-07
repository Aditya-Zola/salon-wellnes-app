<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalonOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $marketing;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->admin = User::where('email', 'admin@gmail.com')->firstOrFail();
        $this->marketing = User::where('email', 'marketing@gmail.com')->firstOrFail();
        $this->cashier = User::where('email', 'kasir@gmail.com')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reservation_can_capture_multiple_treatments_and_staff_as_snapshots(): void
    {
        $facial = $this->treatment('TRT-FACIAL-BARRIER');
        $nail = $this->treatment('TRT-NAIL-GEL-HAND');
        $dita = $this->employee('EMP-DITA');
        $rani = $this->employee('EMP-RANI');
        $sari = $this->employee('EMP-SARI');

        $response = $this->createReservation($this->admin, [
            $this->item($facial->id, '09:00', [
                ['employee_id' => $dita->id, 'role' => 'primary'],
                ['employee_id' => $rani->id, 'role' => 'assistant'],
            ], 90000, 'Harga demo facial'),
            $this->item($nail->id, '11:00', [
                ['employee_id' => $sari->id, 'role' => 'primary'],
            ]),
        ], [
            'name' => 'Pelanggan Multi Layanan',
            'phone' => '081290000001',
            'notes' => 'Satu kunjungan, dua layanan',
        ])->assertCreated()
            ->assertJsonPath('status', 'scheduled')
            ->assertJsonCount(2, 'items');

        $reservationId = (int) $response->json('id');
        $reservationItems = \DB::table('reservation_items')
            ->where('reservation_id', $reservationId)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $reservationItems);
        $this->assertSame($facial->name, $reservationItems[0]->treatment_name);
        $this->assertSame((int) $facial->normal_price, (int) $reservationItems[0]->normal_price);
        $this->assertSame(90000, (int) $reservationItems[0]->unit_price);
        $this->assertSame((int) $facial->duration_minutes, (int) $reservationItems[0]->duration_minutes);
        $this->assertSame('waiting', $reservationItems[0]->work_status);
        $this->assertSame($nail->name, $reservationItems[1]->treatment_name);

        $firstItemStaff = \DB::table('reservation_item_staff')
            ->where('reservation_item_id', $reservationItems[0]->id)
            ->orderBy('employee_id')
            ->get();

        $this->assertCount(2, $firstItemStaff);
        $this->assertSame(
            ['primary', 'assistant'],
            $firstItemStaff->pluck('role')->all(),
        );
        $this->assertSame((int) $reservationItems[0]->commission_amount, (int) $firstItemStaff[0]->commission_amount);
        $this->assertSame(0, (int) $firstItemStaff[1]->commission_amount);

        \DB::table('treatments')->where('id', $facial->id)->update([
            'name' => 'Nama Treatment Berubah',
            'normal_price' => 999999,
            'duration_minutes' => 15,
        ]);

        $persistedSnapshot = \DB::table('reservation_items')->find($reservationItems[0]->id);
        $this->assertSame($facial->name, $persistedSnapshot->treatment_name);
        $this->assertSame((int) $facial->normal_price, (int) $persistedSnapshot->normal_price);
        $this->assertSame((int) $facial->duration_minutes, (int) $persistedSnapshot->duration_minutes);
    }

    public function test_price_override_requires_the_dedicated_permission(): void
    {
        $treatment = $this->treatment('TRT-CREAMBATH-MKRZ');
        $employee = $this->employee('EMP-MAYA');

        $this->createReservation($this->marketing, [
            $this->item($treatment->id, '09:00', [
                ['employee_id' => $employee->id, 'role' => 'primary'],
            ], 1),
        ], ['phone' => '081290000002'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.actual_price');

        $this->assertDatabaseMissing('customers', ['phone' => '081290000002']);
    }

    public function test_conflict_requires_permission_and_reason_before_it_can_be_overridden(): void
    {
        $treatment = $this->treatment('TRT-CREAMBATH-MKRZ');
        $employee = $this->employee('EMP-MAYA');
        $date = today()->addDays(21)->toDateString();
        $items = [$this->item($treatment->id, '10:00', [
            ['employee_id' => $employee->id, 'role' => 'primary'],
        ])];

        $this->createReservation($this->marketing, $items, [
            'date' => $date,
            'name' => 'Jadwal Pertama',
            'phone' => '081290000010',
        ])->assertCreated();

        $conflictPayload = $this->reservationPayload($items, [
            'date' => $date,
            'name' => 'Jadwal Bentrok',
            'phone' => '081290000011',
        ]);

        $this->actingAs($this->marketing)
            ->postJson('/operasional/reservasi', $conflictPayload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'schedule_conflict')
            ->assertJsonPath('can_override', false)
            ->assertJsonPath('requires_reason', true)
            ->assertJsonCount(1, 'conflicts');

        $this->actingAs($this->marketing)
            ->postJson('/operasional/reservasi', [
                ...$conflictPayload,
                'override_conflict' => true,
                'override_reason' => 'Permintaan pelanggan',
            ])
            ->assertForbidden();

        $override = $this->actingAs($this->admin)
            ->postJson('/operasional/reservasi', [
                ...$conflictPayload,
                'override_conflict' => true,
                'override_reason' => 'Disetujui supervisor untuk demo',
            ])
            ->assertCreated();

        $reservationId = (int) $override->json('id');
        $itemId = (int) \DB::table('reservation_items')->where('reservation_id', $reservationId)->value('id');

        $this->assertDatabaseHas('reservation_item_staff', [
            'reservation_item_id' => $itemId,
            'employee_id' => $employee->id,
            'conflict_override_reason' => 'Disetujui supervisor untuk demo',
            'conflict_overridden_by' => $this->admin->id,
        ]);
        $this->assertNotNull(
            \DB::table('reservation_item_staff')
                ->where('reservation_item_id', $itemId)
                ->value('conflict_overridden_at'),
        );

        $activity = \DB::table('activity_logs')
            ->where('action', 'reservation.created')
            ->where('subject_type', 'reservation')
            ->where('subject_id', $reservationId)
            ->first();

        $this->assertNotNull($activity);
        $metadata = json_decode($activity->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($metadata['conflict_overridden']);
        $this->assertSame('Disetujui supervisor untuk demo', $metadata['override_reason']);
        $this->assertNotEmpty($metadata['conflicts']);
    }

    public function test_item_work_status_transitions_set_timestamps_and_synchronize_header(): void
    {
        $firstTreatment = $this->treatment('TRT-CREAMBATH-MKRZ');
        $secondTreatment = $this->treatment('TRT-NAIL-GEL-HAND');
        $maya = $this->employee('EMP-MAYA');
        $sari = $this->employee('EMP-SARI');

        $reservation = $this->createReservation($this->admin, [
            $this->item($firstTreatment->id, '09:00', [
                ['employee_id' => $maya->id, 'role' => 'primary'],
            ]),
            $this->item($secondTreatment->id, '10:30', [
                ['employee_id' => $sari->id, 'role' => 'primary'],
            ]),
        ], ['phone' => '081290000020'])->assertCreated();

        $reservationId = (int) $reservation->json('id');
        $items = \DB::table('reservation_items')
            ->where('reservation_id', $reservationId)
            ->orderBy('sort_order')
            ->get();

        $this->updateItemStatus($reservationId, $items[0]->id, 'finished')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertNull(\DB::table('reservation_items')->where('id', $items[0]->id)->value('finished_at'));

        Carbon::setTestNow(Carbon::parse('2026-08-20 08:00:00', config('app.timezone')));
        $this->updateItemStatus($reservationId, $items[0]->id, 'in_progress')
            ->assertOk()
            ->assertJsonPath('work_status', 'in_progress')
            ->assertJsonPath('reservation_status', 'in_service');

        Carbon::setTestNow(now()->addMinutes(60));
        $this->updateItemStatus($reservationId, $items[0]->id, 'overtime')->assertOk();

        Carbon::setTestNow(now()->addMinutes(10));
        $this->updateItemStatus($reservationId, $items[0]->id, 'continue')->assertOk();

        Carbon::setTestNow(now()->addMinutes(10));
        $this->updateItemStatus($reservationId, $items[0]->id, 'ready')->assertOk();

        Carbon::setTestNow(now()->addMinutes(10));
        $this->updateItemStatus($reservationId, $items[0]->id, 'finished')
            ->assertOk()
            ->assertJsonPath('reservation_status', 'in_service');

        $firstItem = \DB::table('reservation_items')->find($items[0]->id);
        $this->assertNotNull($firstItem->started_at);
        $this->assertNotNull($firstItem->overtime_at);
        $this->assertNotNull($firstItem->continued_at);
        $this->assertNotNull($firstItem->ready_at);
        $this->assertNotNull($firstItem->finished_at);

        $this->updateItemStatus($reservationId, $items[1]->id, 'in_progress')->assertOk();
        $this->updateItemStatus($reservationId, $items[1]->id, 'finished')
            ->assertOk()
            ->assertJsonPath('reservation_status', 'completed');

        $this->assertDatabaseHas('reservations', [
            'id' => $reservationId,
            'status' => 'completed',
        ]);
        $snapshot = $this->actingAs($this->cashier)->getJson('/operasional/data')->assertOk()->json();
        $snapshotReservation = collect($snapshot['reservations'])->firstWhere('id', $reservationId);
        $this->assertFalse($snapshotReservation['is_paid']);
        $this->assertNull($snapshotReservation['transaction_id']);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'reservation_item.status_changed',
            'subject_type' => 'reservation_item',
            'subject_id' => $items[0]->id,
        ]);
    }

    public function test_checkout_is_rejected_until_every_billable_item_is_finished(): void
    {
        $treatment = $this->treatment('TRT-NAIL-GEL-HAND');
        $employee = $this->employee('EMP-SARI');
        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '13:00', [
                ['employee_id' => $employee->id, 'role' => 'primary'],
            ]),
        ], ['phone' => '081290000030'])->assertCreated();

        $reservationId = (int) $reservation->json('id');
        $cash = $this->paymentMethod('CASH');

        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => (int) $treatment->normal_price,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation_id');

        $this->assertDatabaseMissing('transactions', ['reservation_id' => $reservationId]);
        $this->assertDatabaseCount('transaction_payments', 0);
        $this->assertDatabaseCount('cash_entries', 0);
    }

    public function test_split_payment_must_equal_invoice_total_exactly(): void
    {
        [$reservationId, $total] = $this->finishedTwoItemReservation('081290000040');
        $cash = $this->paymentMethod('CASH');
        $qris = $this->paymentMethod('QRIS_BCA');

        $basePayload = [
            'reservation_id' => $reservationId,
            'payments' => [
                ['payment_method_id' => $cash->id, 'amount' => 80000],
                [
                    'payment_method_id' => $qris->id,
                    'amount' => $total - 80000,
                    'reference_number' => 'QRIS-DEMO-001',
                ],
            ],
        ];

        $underpaid = $basePayload;
        $underpaid['payments'][1]['amount']--;
        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', $underpaid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments');

        $overpaid = $basePayload;
        $overpaid['payments'][1]['amount']++;
        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', $overpaid)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payments');

        $payment = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                ...$basePayload,
                'idempotency_key' => 'split-payment-test',
            ])
            ->assertCreated()
            ->assertJsonPath('total', $total)
            ->assertJsonPath('paid_amount', $total)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('idempotent_replay', false);

        $transactionId = (int) $payment->json('id');
        $this->assertSame(
            $total,
            (int) \DB::table('transaction_payments')->where('transaction_id', $transactionId)->sum('amount'),
        );
        $this->assertSame(2, \DB::table('transaction_payments')->where('transaction_id', $transactionId)->count());
        $this->assertSame(2, \DB::table('transaction_items')->where('transaction_id', $transactionId)->count());
        $this->assertSame(2, \DB::table('cash_entries')->count());
        $this->assertDatabaseHas('reservations', ['id' => $reservationId, 'status' => 'completed']);
    }

    public function test_repeated_checkout_replays_existing_invoice_without_duplicate_side_effects(): void
    {
        $treatment = $this->treatment('TRT-CREAMBATH-MKRZ');
        $employee = $this->employee('EMP-MAYA');
        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '15:00', [
                ['employee_id' => $employee->id, 'role' => 'primary'],
            ]),
        ], ['phone' => '081290000050'])->assertCreated();

        $reservationId = (int) $reservation->json('id');
        $customerId = (int) \DB::table('reservations')->where('id', $reservationId)->value('customer_id');
        $visitsBefore = (int) \DB::table('customers')->where('id', $customerId)->value('visit_count');
        \DB::table('reservation_items')->where('reservation_id', $reservationId)->update([
            'work_status' => 'finished',
            'finished_at' => now(),
        ]);

        $payload = [
            'reservation_id' => $reservationId,
            'idempotency_key' => 'checkout-replay-test',
            'payments' => [[
                'payment_method_id' => $this->paymentMethod('CASH')->id,
                'amount' => (int) $treatment->normal_price,
            ]],
        ];

        $first = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', $payload)
            ->assertCreated()
            ->assertJsonPath('idempotent_replay', false);

        $transactionId = (int) $first->json('id');
        $second = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', $payload)
            ->assertOk()
            ->assertJsonPath('id', $transactionId)
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame($first->json('number'), $second->json('number'));
        $this->assertSame(1, \DB::table('transactions')->where('reservation_id', $reservationId)->count());
        $this->assertSame(1, \DB::table('transaction_items')->where('transaction_id', $transactionId)->count());
        $this->assertSame(1, \DB::table('transaction_payments')->where('transaction_id', $transactionId)->count());
        $this->assertSame(1, \DB::table('cash_entries')->whereIn(
            'transaction_payment_id',
            \DB::table('transaction_payments')->where('transaction_id', $transactionId)->select('id'),
        )->count());
        $this->assertSame(
            $visitsBefore + 1,
            (int) \DB::table('customers')->where('id', $customerId)->value('visit_count'),
        );

        $cashierReservation = collect(
            $this->actingAs($this->cashier)->getJson('/operasional/data')->assertOk()->json('reservations'),
        )->firstWhere('id', $reservationId);
        $this->assertTrue($cashierReservation['is_paid']);
        $this->assertSame($transactionId, $cashierReservation['transaction_id']);

        $marketingReservation = collect(
            $this->actingAs($this->marketing)->getJson('/operasional/data')->assertOk()->json('reservations'),
        )->firstWhere('id', $reservationId);
        $this->assertTrue($marketingReservation['is_paid']);
        $this->assertArrayNotHasKey('transaction_id', $marketingReservation);
    }

    public function test_dashboard_only_user_does_not_receive_unauthorized_domain_or_private_data(): void
    {
        $role = Role::create([
            'name' => 'dashboard-only',
            'display_name' => 'Dashboard Only',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions(['dashboard.view']);
        $viewer = User::factory()->create();
        $viewer->syncRoles($role);

        $payload = $this->actingAs($viewer)
            ->getJson('/operasional/data')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('dashboard', $payload);

        foreach ([
            'reservations',
            'treatments',
            'employees',
            'members',
            'customers',
            'products',
            'stock_movements',
            'transactions',
            'payment_methods',
            'payrolls',
            'activities',
            'promotions',
        ] as $privateDomainKey) {
            $this->assertArrayNotHasKey($privateDomainKey, $payload);
        }

        $serialized = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('081234567801', $serialized);
        $this->assertStringNotContainsString('"phone"', $serialized);
        $this->assertStringNotContainsString('"employee_name"', $serialized);
        $this->assertStringNotContainsString('"user_agent"', $serialized);
    }

    public function test_insufficient_recipe_stock_rolls_back_the_entire_checkout(): void
    {
        $treatment = $this->treatment('TRT-CREAMBATH-MKRZ');
        $employee = $this->employee('EMP-MAYA');
        $products = \DB::table('products')->orderBy('id')->take(2)->get();
        $this->assertCount(2, $products);

        \DB::table('products')->where('id', $products[0]->id)->update(['current_stock' => '10.0000']);
        \DB::table('products')->where('id', $products[1]->id)->update(['current_stock' => '0.0000']);

        foreach ($products as $product) {
            \DB::table('treatment_product_recipes')->insert([
                'treatment_id' => $treatment->id,
                'product_id' => $product->id,
                'unit_id' => $product->usage_unit_id,
                'quantity' => '1.0000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '16:00', [
                ['employee_id' => $employee->id, 'role' => 'primary'],
            ]),
        ], ['phone' => '081290000060'])->assertCreated();
        $reservationId = (int) $reservation->json('id');
        $customerId = (int) \DB::table('reservations')->where('id', $reservationId)->value('customer_id');
        $visitsBefore = (int) \DB::table('customers')->where('id', $customerId)->value('visit_count');

        \DB::table('reservation_items')->where('reservation_id', $reservationId)->update([
            'work_status' => 'finished',
            'finished_at' => now(),
        ]);

        $countsBefore = [
            'transactions' => \DB::table('transactions')->count(),
            'transaction_items' => \DB::table('transaction_items')->count(),
            'transaction_payments' => \DB::table('transaction_payments')->count(),
            'cash_entries' => \DB::table('cash_entries')->count(),
            'stock_movements' => \DB::table('stock_movements')->count(),
        ];

        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'idempotency_key' => 'insufficient-stock-test',
                'payments' => [[
                    'payment_method_id' => $this->paymentMethod('CASH')->id,
                    'amount' => (int) $treatment->normal_price,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation_id');

        foreach ($countsBefore as $table => $count) {
            $this->assertSame($count, \DB::table($table)->count(), "Table {$table} was not rolled back.");
        }

        $this->assertSame(10.0, (float) \DB::table('products')->where('id', $products[0]->id)->value('current_stock'));
        $this->assertSame(0.0, (float) \DB::table('products')->where('id', $products[1]->id)->value('current_stock'));
        $this->assertSame(
            $visitsBefore,
            (int) \DB::table('customers')->where('id', $customerId)->value('visit_count'),
        );
        $this->assertDatabaseMissing('transactions', ['reservation_id' => $reservationId]);
        $this->assertDatabaseHas('reservations', ['id' => $reservationId, 'status' => 'scheduled']);
    }

    private function createReservation(User $actor, array $items, array $overrides = []): TestResponse
    {
        return $this->actingAs($actor)->postJson(
            '/operasional/reservasi',
            $this->reservationPayload($items, $overrides),
        );
    }

    private function reservationPayload(array $items, array $overrides = []): array
    {
        return [
            'name' => 'Pelanggan Phase 1',
            'phone' => '081299999999',
            'date' => today()->addDays(20)->toDateString(),
            'source' => 'walk_in',
            'notes' => null,
            'items' => $items,
            ...$overrides,
        ];
    }

    private function item(int $treatmentId, string $startTime, array $staff, ?int $actualPrice = null, ?string $notes = null): array
    {
        return array_filter([
            'treatment_id' => $treatmentId,
            'start_time' => $startTime,
            'actual_price' => $actualPrice,
            'notes' => $notes,
            'staff' => $staff,
        ], fn (mixed $value): bool => $value !== null);
    }

    private function updateItemStatus(int $reservationId, int $itemId, string $status): TestResponse
    {
        return $this->actingAs($this->admin)->patchJson(
            "/operasional/reservasi/{$reservationId}/item/{$itemId}/status",
            ['status' => $status],
        );
    }

    private function finishedTwoItemReservation(string $phone): array
    {
        $firstTreatment = $this->treatment('TRT-CREAMBATH-MKRZ');
        $secondTreatment = $this->treatment('TRT-NAIL-GEL-HAND');
        $maya = $this->employee('EMP-MAYA');
        $sari = $this->employee('EMP-SARI');
        $response = $this->createReservation($this->admin, [
            $this->item($firstTreatment->id, '09:00', [
                ['employee_id' => $maya->id, 'role' => 'primary'],
            ]),
            $this->item($secondTreatment->id, '10:30', [
                ['employee_id' => $sari->id, 'role' => 'primary'],
            ]),
        ], ['phone' => $phone])->assertCreated();

        $reservationId = (int) $response->json('id');
        \DB::table('reservation_items')->where('reservation_id', $reservationId)->update([
            'work_status' => 'finished',
            'finished_at' => now(),
        ]);

        return [$reservationId, (int) $firstTreatment->normal_price + (int) $secondTreatment->normal_price];
    }

    private function treatment(string $code): object
    {
        return \DB::table('treatments')->where('code', $code)->firstOrFail();
    }

    private function employee(string $code): object
    {
        return \DB::table('employees')->where('code', $code)->firstOrFail();
    }

    private function paymentMethod(string $code): object
    {
        return \DB::table('payment_methods')->where('code', $code)->firstOrFail();
    }
}
