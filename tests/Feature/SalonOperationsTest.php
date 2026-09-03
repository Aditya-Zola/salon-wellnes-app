<?php

namespace Tests\Feature;

use App\Http\Services\SpreadsheetExportService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

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
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('reservation.customer_name', 'Pelanggan Multi Layanan')
            ->assertJsonPath('reservation.status', 'scheduled')
            ->assertJsonPath('reservation.items.0.treatment_name', $facial->name)
            ->assertJsonPath('reservation.items.0.staff.0.employee_name', $dita->name)
            ->assertJsonCount(2, 'reservation.items')
            ->assertJsonCount(2, 'reservation.items.0.staff');

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
        $this->assertSame(4500, (int) $reservationItems[0]->commission_amount);
        $this->assertSame(2250, (int) $firstItemStaff[0]->commission_amount);
        $this->assertSame(2250, (int) $firstItemStaff[1]->commission_amount);

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

    public function test_cashier_can_start_a_walk_in_transaction_from_the_cashier_page(): void
    {
        $treatment = $this->treatment('TRT-NAIL-GEL-HAND');
        $therapist = $this->employee('EMP-SARI');
        $payload = $this->reservationPayload([
            $this->item($treatment->id, '15:00', [
                ['employee_id' => $therapist->id, 'role' => 'primary'],
            ]),
        ], [
            'name' => 'Pelanggan Walk-in Kasir',
            'phone' => '081290000011',
        ]);

        $this->assertFalse($this->cashier->can('reservations.create'));
        $this->actingAs($this->cashier)
            ->get('/')
            ->assertOk()
            ->assertSee('id="cashier-new-transaction"', false)
            ->assertSee('Transaksi baru');

        $this->actingAs($this->cashier)
            ->postJson('/operasional/reservasi', $payload)
            ->assertForbidden();

        $response = $this->actingAs($this->cashier)
            ->postJson('/operasional/kasir/transaksi', $payload)
            ->assertCreated()
            ->assertJsonPath('reservation.customer_name', 'Pelanggan Walk-in Kasir')
            ->assertJsonPath('reservation.is_paid', false);

        $this->assertDatabaseHas('reservations', [
            'id' => $response->json('id'),
            'source' => 'walk_in',
            'status' => 'scheduled',
        ]);
    }

    public function test_payroll_can_be_created_for_a_registered_employee(): void
    {
        $employee = $this->employee('EMP-SARI');
        [$reservationId, $total] = $this->finishedTwoItemReservation('081290000099');
        $paymentMethod = $this->paymentMethod('CASH');

        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'payments' => [[
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $total,
                ]],
            ])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/operasional/penggajian', [
                'employee_id' => $employee->id,
                'period' => today()->format('Y-m'),
                'base_salary' => 3500000,
                'bonus' => 150000,
                'overtime' => 50000,
                'late_duration_minutes' => 15,
                'late_deduction' => 25000,
                'other_deduction' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Data penggajian berhasil ditambahkan.');

        $this->assertDatabaseHas('payrolls', [
            'employee_id' => $employee->id,
            'period' => today()->format('Y-m'),
            'employee_name' => 'Sari',
            'base_salary' => 3500000,
            'bonus' => 150000,
            'overtime' => 50000,
            'late_duration_minutes' => 15,
            'late_deduction' => 25000,
            'other_deduction' => 0,
            'commission' => 4000,
            'status' => 'draft',
        ]);

        $this->actingAs($this->admin)
            ->postJson('/operasional/penggajian', [
                'employee_id' => $employee->id,
                'period' => today()->format('Y-m'),
                'base_salary' => 3500000,
            ])
            ->assertUnprocessable();
    }

    public function test_checkout_refreshes_commission_on_an_existing_draft_payroll(): void
    {
        $employee = $this->employee('EMP-SARI');
        $cash = $this->paymentMethod('CASH');
        [$firstReservationId, $firstTotal] = $this->finishedTwoItemReservation('081290000098');

        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $firstReservationId,
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => $firstTotal,
                ]],
            ])
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/operasional/penggajian', [
                'employee_id' => $employee->id,
                'period' => today()->format('Y-m'),
                'base_salary' => 3500000,
                'bonus' => 0,
                'overtime' => 0,
                'late_duration_minutes' => 0,
                'late_deduction' => 0,
                'other_deduction' => 0,
            ])
            ->assertCreated();

        [$secondReservationId, $secondTotal] = $this->finishedTwoItemReservation('081290000097');
        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $secondReservationId,
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => $secondTotal,
                ]],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('payrolls', [
            'employee_id' => $employee->id,
            'period' => today()->format('Y-m'),
            'commission' => 8000,
            'net_salary' => 3508000,
            'status' => 'draft',
        ]);
    }

    public function test_sales_history_includes_paid_invoice_details_for_reprinting(): void
    {
        [$reservationId, $total] = $this->finishedTwoItemReservation('081290000188');
        $paymentMethod = $this->paymentMethod('CASH');

        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'payments' => [[
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $total,
                    'tendered_amount' => $total + 10000,
                ]],
            ])
            ->assertCreated();

        $snapshot = $this->actingAs($this->cashier)
            ->getJson('/operasional/data')
            ->assertOk()
            ->json();

        $transaction = collect($snapshot['transactions'])->firstWhere('reservation_id', $reservationId);
        $this->assertNotNull($transaction);
        $this->assertSame('paid', $transaction['status']);
        $this->assertCount(2, $transaction['items']);
        $this->assertSame('Tunai', $transaction['payments'][0]['payment_method_name']);
        $this->assertSame($total + 10000, (int) $transaction['payments'][0]['tendered_amount']);
        $this->assertSame('Kasir Selesa', $transaction['cashier_name']);

        $this->actingAs($this->cashier)
            ->get("/operasional/penjualan/{$transaction['id']}/nota.pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_schedule_and_stock_history_can_be_exported_as_excel(): void
    {
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $employee = $this->employee('EMP-DITA');
        $this->createReservation($this->admin, [
            $this->item($treatment->id, '17:00', [
                ['employee_id' => $employee->id, 'role' => 'primary'],
            ]),
        ], [
            'date' => today()->toDateString(),
            'name' => 'Pelanggan Ekspor',
            'phone' => '081290000089',
        ])->assertCreated();

        $this->actingAs($this->admin)
            ->get('/')
            ->assertOk()
            ->assertSee('id="export-schedule"', false);

        $schedule = $this->actingAs($this->admin)
            ->get('/operasional/reservasi/ekspor?date='.today()->toDateString())
            ->assertOk();
        $schedule->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $scheduleContent = $schedule->streamedContent();
        $this->assertStringStartsWith("PK\x03\x04", $scheduleContent);

        $tempSchedule = tempnam(sys_get_temp_dir(), 'selesa-schedule-test-');
        $this->assertNotFalse($tempSchedule);
        file_put_contents($tempSchedule, $scheduleContent);
        $workbook = new ZipArchive;
        $this->assertTrue($workbook->open($tempSchedule));
        try {
            $worksheet = $workbook->getFromName('xl/worksheets/sheet1.xml');
            $styles = $workbook->getFromName('xl/styles.xml');
            $this->assertIsString($worksheet);
            $this->assertIsString($styles);
            $this->assertStringContainsString('BOOKING', $worksheet);
            $this->assertStringContainsString('NOMINAL SATUAN', $worksheet);
            $this->assertStringContainsString('KOMISI SATUAN', $worksheet);
            $this->assertStringContainsString('Pelanggan Ekspor', $worksheet);
            $this->assertStringContainsString('TOTAL PEMBAYARAN', $worksheet);
            $this->assertNotFalse(simplexml_load_string($worksheet));
            $this->assertNotFalse(simplexml_load_string($styles));
        } finally {
            $workbook->close();
            @unlink($tempSchedule);
        }

        $stockProduct = DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();
        $this->actingAs($this->admin)
            ->patchJson("/operasional/produk/{$stockProduct->id}/stok", [
                'type' => 'masuk',
                'quantity' => '5',
                'source' => 'Stok masuk untuk pengujian export',
            ])
            ->assertOk();

        $stock = $this->actingAs($this->admin)
            ->get('/operasional/produk/riwayat-ekspor?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk();
        $stock->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $stockContent = $stock->streamedContent();
        $this->assertStringStartsWith("PK\x03\x04", $stockContent);

        $tempStock = tempnam(sys_get_temp_dir(), 'selesa-stock-test-');
        $this->assertNotFalse($tempStock);
        file_put_contents($tempStock, $stockContent);
        $stockWorkbook = new ZipArchive;
        $this->assertTrue($stockWorkbook->open($tempStock));
        try {
            $stockWorksheet = $stockWorkbook->getFromName('xl/worksheets/sheet1.xml');
            $stockStyles = $stockWorkbook->getFromName('xl/styles.xml');
            $this->assertIsString($stockWorksheet);
            $this->assertIsString($stockStyles);
            $this->assertStringContainsString('REKAP STOK IN-OUT', $stockWorkbook->getFromName('xl/workbook.xml'));
            $this->assertStringContainsString('JML PROD. MASUK', $stockWorksheet);
            $this->assertStringContainsString('BERAT GROSS PROD. MASUK', $stockWorksheet);
            $this->assertStringContainsString('DOSIS PER CUST', $stockWorksheet);
            $this->assertStringContainsString('STOK PROD. KELUAR PER CUST', $stockWorksheet);
            $this->assertStringContainsString('SISA STOK PROD', $stockWorksheet);
            $this->assertStringContainsString('HERBAL DRINK', $stockWorksheet);
            $this->assertNotFalse(simplexml_load_string($stockWorksheet));
            $this->assertNotFalse(simplexml_load_string($stockStyles));
        } finally {
            $stockWorkbook->close();
            @unlink($tempStock);
        }
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

    public function test_recipe_can_replace_multiple_products_in_one_request(): void
    {
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $products = \DB::table('products')->orderBy('id')->take(2)->get();

        $this->actingAs($this->admin)
            ->putJson("/operasional/treatment/{$treatment->id}/resep", [
                'items' => [
                    ['product_id' => $products[0]->id, 'quantity' => '12.5000'],
                    ['product_id' => $products[1]->id, 'quantity' => '3'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Resep treatment berhasil diperbarui.');

        $this->assertSame(2, \DB::table('treatment_product_recipes')->where('treatment_id', $treatment->id)->count());
        $this->assertDatabaseHas('treatment_product_recipes', [
            'treatment_id' => $treatment->id,
            'product_id' => $products[0]->id,
            'quantity' => '12.5000',
        ]);

        $this->actingAs($this->admin)
            ->putJson("/operasional/treatment/{$treatment->id}/resep", [
                'items' => [
                    ['product_id' => $products[1]->id, 'quantity' => '5'],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, \DB::table('treatment_product_recipes')->where('treatment_id', $treatment->id)->count());
        $this->assertDatabaseMissing('treatment_product_recipes', [
            'treatment_id' => $treatment->id,
            'product_id' => $products[0]->id,
        ]);
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

    public function test_checkout_allows_scheduled_reservation_and_service_can_continue_after_payment(): void
    {
        $treatment = $this->treatment('TRT-NAIL-GEL-HAND');
        $employee = $this->employee('EMP-SARI');
        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '13:00', [
                ['employee_id' => $employee->id, 'role' => 'primary'],
            ]),
        ], ['phone' => '081290000030'])->assertCreated();

        $reservationId = (int) $reservation->json('id');
        $customerId = (int) \DB::table('reservations')->where('id', $reservationId)->value('customer_id');
        $visitsBefore = (int) \DB::table('customers')->where('id', $customerId)->value('visit_count');
        $itemId = (int) \DB::table('reservation_items')->where('reservation_id', $reservationId)->value('id');
        $cash = $this->paymentMethod('CASH');

        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => (int) $treatment->normal_price,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'paid');

        $this->assertDatabaseHas('transactions', ['reservation_id' => $reservationId, 'status' => 'paid']);
        $this->assertDatabaseHas('reservations', ['id' => $reservationId, 'status' => 'scheduled']);
        $this->assertSame($visitsBefore, (int) \DB::table('customers')->where('id', $customerId)->value('visit_count'));

        $this->updateItemStatus($reservationId, $itemId, 'in_progress')->assertOk();
        $this->updateItemStatus($reservationId, $itemId, 'finished')
            ->assertOk()
            ->assertJsonPath('reservation_status', 'completed');

        $this->assertSame($visitsBefore + 1, (int) \DB::table('customers')->where('id', $customerId)->value('visit_count'));
    }

    public function test_split_payment_must_equal_invoice_total_exactly(): void
    {
        [$reservationId, $total] = $this->finishedTwoItemReservation('081290000040');
        $cash = $this->paymentMethod('CASH');
        $qris = $this->paymentMethod('QRIS-001');

        $basePayload = [
            'reservation_id' => $reservationId,
            'payments' => [
                ['payment_method_id' => $cash->id, 'amount' => 80000],
                [
                    'payment_method_id' => $qris->id,
                    'amount' => $total - 80000,
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
        $this->assertSame(0, \DB::table('cash_entries')->count());
        $this->assertDatabaseHas('reservations', ['id' => $reservationId, 'status' => 'completed']);

        $dashboard = $this->actingAs($this->admin)->getJson('/operasional/data')->assertOk()->json('dashboard');
        $revenueByMethod = collect($dashboard['revenue_by_payment_method_today']);
        $this->assertSame($total, (int) $revenueByMethod->firstWhere('key', 'total')['total']);
        $this->assertSame(80000, (int) $revenueByMethod->firstWhere('name', 'Tunai')['total']);
        $this->assertSame($total - 80000, (int) $revenueByMethod->firstWhere('key', 'method-'.$qris->id)['total']);
        $this->assertSame(0, (int) $revenueByMethod->firstWhere('type', 'bank_transfer')['total']);
    }

    public function test_dashboard_shows_payment_flows_per_configured_method_and_top_treatments(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 10:00:00'));
        $bank = $this->paymentMethod('BANK-001');
        $qris = $this->paymentMethod('QRIS-001');
        DB::table('payment_methods')->where('id', $bank->id)->update([
            'name' => 'BCA Transfer',
            'account_name' => 'Selesa Salon',
            'account_number' => '1234567890',
        ]);
        DB::table('payment_methods')->where('id', $qris->id)->update([
            'name' => 'QRIS BCA',
            'account_name' => 'QRIS Selesa Salon',
            'account_number' => 'NMID-SEL-001',
        ]);

        [$reservationId, $total] = $this->finishedTwoItemReservation('081290000177');
        $bankAmount = 100000;
        $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'payments' => [
                    ['payment_method_id' => $bank->id, 'amount' => $bankAmount],
                    ['payment_method_id' => $qris->id, 'amount' => $total - $bankAmount],
                ],
            ])
            ->assertCreated();

        $dashboard = $this->actingAs($this->admin)->getJson('/operasional/data')->assertOk()->json('dashboard');
        $monthFlows = collect($dashboard['payment_flows_month']);
        $bankFlow = $monthFlows->firstWhere('id', $bank->id);
        $qrisFlow = $monthFlows->firstWhere('id', $qris->id);

        $this->assertSame('BCA Transfer', $bankFlow['name']);
        $this->assertSame('Selesa Salon', $bankFlow['account_name']);
        $this->assertSame($bankAmount, (int) $bankFlow['inflow']);
        $this->assertSame(0, (int) $bankFlow['outflow']);
        $this->assertSame($bankAmount, (int) $bankFlow['net']);
        $this->assertSame('QRIS BCA', $qrisFlow['name']);
        $this->assertSame($total - $bankAmount, (int) $qrisFlow['inflow']);

        $popularTreatments = collect($dashboard['treatment_most_frequent_current_month']);
        $this->assertCount(2, $popularTreatments);
        $this->assertSame(1.0, (float) $popularTreatments->firstWhere('name', 'Makarizo Creambath')['total']);
        $this->assertSame(1.0, (float) $popularTreatments->firstWhere('name', 'Nail Gel Polish - Hand')['total']);
    }

    public function test_checkout_applies_configured_payment_charge_and_cashier_can_turn_it_off(): void
    {
        $bank = $this->paymentMethod('BANK-001');
        DB::table('payment_methods')->where('id', $bank->id)->update([
            'charge_percent' => '2.0000',
            'charge_default_enabled' => true,
        ]);

        [$chargedReservationId, $baseTotal] = $this->finishedTwoItemReservation('081290000042');
        $charge = (int) round($baseTotal * 0.02);
        $charged = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $chargedReservationId,
                'payments' => [[
                    'payment_method_id' => $bank->id,
                    'amount' => $baseTotal,
                    'charge_enabled' => true,
                    'tendered_amount' => $baseTotal + $charge,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('base_total', $baseTotal)
            ->assertJsonPath('payment_charge_amount', $charge)
            ->assertJsonPath('total', $baseTotal + $charge);

        $this->assertDatabaseHas('transactions', [
            'id' => $charged->json('id'),
            'payment_charge_amount' => $charge,
            'total' => $baseTotal + $charge,
        ]);
        $this->assertDatabaseHas('transaction_payments', [
            'transaction_id' => $charged->json('id'),
            'payment_method_id' => $bank->id,
            'base_amount' => $baseTotal,
            'charge_percent' => '2.0000',
            'charge_amount' => $charge,
            'charge_enabled' => true,
            'amount' => $baseTotal + $charge,
        ]);

        [$unchargedReservationId, $unchargedBaseTotal] = $this->finishedTwoItemReservation('081290000043');
        $uncharged = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $unchargedReservationId,
                'payments' => [[
                    'payment_method_id' => $bank->id,
                    'amount' => $unchargedBaseTotal,
                    'charge_enabled' => false,
                    'tendered_amount' => $unchargedBaseTotal,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('payment_charge_amount', 0)
            ->assertJsonPath('total', $unchargedBaseTotal);

        $this->assertDatabaseHas('transaction_payments', [
            'transaction_id' => $uncharged->json('id'),
            'charge_enabled' => false,
            'charge_amount' => 0,
            'amount' => $unchargedBaseTotal,
        ]);
    }

    public function test_cash_checkout_records_received_amount_and_change(): void
    {
        [$reservationId, $total] = $this->finishedTwoItemReservation('081290000041');
        $cash = $this->paymentMethod('CASH');

        $payment = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => $total,
                    'tendered_amount' => $total + 50000,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('change_amount', 50000);

        $transactionId = (int) $payment->json('id');
        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'change_amount' => 50000,
        ]);
        $this->assertDatabaseHas('transaction_payments', [
            'transaction_id' => $transactionId,
            'amount' => $total,
            'tendered_amount' => $total + 50000,
        ]);
    }

    public function test_checkout_can_add_sold_product_and_decrease_its_stock(): void
    {
        $treatment = $this->treatment('TRT-NAIL-GEL-HAND');
        $employee = $this->employee('EMP-SARI');
        $product = \DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->first();
        $cash = $this->paymentMethod('CASH');
        $this->assertNotNull($product);

        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '15:00', [
                ['employee_id' => $employee->id, 'role' => 'primary'],
            ]),
        ], ['phone' => '081290000070'])->assertCreated();
        $reservationId = (int) $reservation->json('id');
        \DB::table('reservation_items')->where('reservation_id', $reservationId)->update([
            'work_status' => 'finished',
            'finished_at' => now(),
        ]);

        $quantity = '2.0000';
        $total = (int) $treatment->normal_price + ((int) $product->selling_price * 2);
        $checkout = $this->actingAs($this->cashier)->postJson('/operasional/pembayaran', [
            'reservation_id' => $reservationId,
            'idempotency_key' => 'checkout-product-sale-test',
            'product_items' => [[
                'product_id' => $product->id,
                'quantity' => $quantity,
            ]],
            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => $total,
            ]],
        ])->assertCreated()->assertJsonPath('total', $total);

        $transactionId = (int) $checkout->json('id');
        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transactionId,
            'item_type' => 'product',
            'item_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->selling_price,
            'total_amount' => (int) $product->selling_price * 2,
        ]);
        $this->assertSame(
            (float) $product->current_stock - 2,
            (float) \DB::table('products')->where('id', $product->id)->value('current_stock'),
        );
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'source_type' => 'transaction_sale',
            'source_id' => $transactionId,
        ]);
    }

    public function test_admin_can_partially_return_product_refund_money_restock_and_print_receipt(): void
    {
        Carbon::setTestNow('2026-08-14 14:30:00');
        [$transactionId, $product, $stockBeforeSale, $transactionTotal] = $this->paidProductTransaction('081290000074');
        $cash = $this->paymentMethod('CASH');
        $transactionItem = \DB::table('transaction_items')
            ->where('transaction_id', $transactionId)
            ->where('item_type', 'product')
            ->firstOrFail();

        $response = $this->actingAs($this->admin)
            ->postJson("/operasional/penjualan/{$transactionId}/retur", [
                'items' => [[
                    'transaction_item_id' => $transactionItem->id,
                    'quantity' => '1.0000',
                    'restock' => true,
                ]],
                'payment_method_id' => $cash->id,
                'reason' => 'Kemasan produk rusak saat diterima pelanggan.',
                'idempotency_key' => 'return-product-partial-test',
            ])
            ->assertCreated()
            ->assertJsonPath('total_amount', (int) $product->selling_price);

        $returnId = (int) $response->json('id');
        $this->assertStringStartsWith('RTN-20260814-', $response->json('number'));
        $this->assertDatabaseHas('sales_returns', [
            'id' => $returnId,
            'transaction_id' => $transactionId,
            'refund_payment_method_id' => $cash->id,
            'total_amount' => $product->selling_price,
            'status' => 'posted',
        ]);
        $this->assertDatabaseHas('sales_return_items', [
            'sales_return_id' => $returnId,
            'transaction_item_id' => $transactionItem->id,
            'product_id' => $product->id,
            'quantity' => '1.0000',
            'restocked' => true,
        ]);
        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'refunded_amount' => $product->selling_price,
        ]);
        $this->assertSame(
            $stockBeforeSale - 1,
            (float) \DB::table('products')->where('id', $product->id)->value('current_stock'),
        );
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'in',
            'source_type' => 'sales_return',
            'source_id' => $returnId,
            'reference' => $response->json('number'),
        ]);

        $sales = $this->actingAs($this->admin)->getJson('/operasional/penjualan')->assertOk()->json('data.0');
        $this->assertSame($transactionTotal - (int) $product->selling_price, $sales['net_total']);
        $this->assertSame(1.0, (float) collect($sales['items'])->firstWhere('id', $transactionItem->id)['returned_quantity']);
        $this->assertCount(1, $sales['returns']);

        $snapshot = $this->actingAs($this->admin)->getJson('/operasional/data')->assertOk()->json();
        $this->assertSame($transactionTotal - (int) $product->selling_price, $snapshot['dashboard']['revenue_today']);
        $this->assertSame(0, $snapshot['dashboard']['month_income']);
        $this->assertSame(0, $snapshot['dashboard']['month_expense']);
        $this->assertSame(0, $snapshot['dashboard']['month_balance']);
        $this->assertFalse(collect($snapshot['cash_entries'])->contains(
            fn (array $entry): bool => $entry['category'] === 'Retur penjualan',
        ));

        $this->actingAs($this->admin)
            ->getJson('/operasional/retur')
            ->assertOk()
            ->assertJsonPath('data.0.id', $returnId)
            ->assertJsonPath('data.0.transaction_number', $sales['number'])
            ->assertJsonPath('data.0.total_amount', (int) $product->selling_price);

        $this->actingAs($this->admin)
            ->get("/operasional/retur/{$returnId}/struk.pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($this->admin)
            ->postJson("/operasional/penjualan/{$transactionId}/retur", [
                'items' => [[
                    'transaction_item_id' => $transactionItem->id,
                    'quantity' => '1.0000',
                    'restock' => true,
                ]],
                'payment_method_id' => $cash->id,
                'reason' => 'Kemasan produk rusak saat diterima pelanggan.',
                'idempotency_key' => 'return-product-partial-test',
            ])
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);
        $this->assertSame(1, \DB::table('sales_returns')->count());
    }

    public function test_product_return_rejects_unauthorized_and_excess_quantities_atomically(): void
    {
        [$transactionId, $product, $stockBeforeSale] = $this->paidProductTransaction('081290000075');
        $cash = $this->paymentMethod('CASH');
        $transactionItem = \DB::table('transaction_items')
            ->where('transaction_id', $transactionId)
            ->where('item_type', 'product')
            ->firstOrFail();
        $payload = [
            'items' => [[
                'transaction_item_id' => $transactionItem->id,
                'quantity' => '3.0000',
                'restock' => true,
            ]],
            'payment_method_id' => $cash->id,
            'reason' => 'Jumlah retur tidak valid.',
        ];

        $this->actingAs($this->cashier)
            ->postJson("/operasional/penjualan/{$transactionId}/retur", $payload)
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson("/operasional/penjualan/{$transactionId}/retur", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, \DB::table('sales_returns')->count());
        $this->assertSame(0, (int) \DB::table('transactions')->where('id', $transactionId)->value('refunded_amount'));
        $this->assertSame(
            $stockBeforeSale - 2,
            (float) \DB::table('products')->where('id', $product->id)->value('current_stock'),
        );
        $this->assertDatabaseMissing('stock_movements', ['source_type' => 'sales_return']);
    }

    public function test_product_return_can_refund_without_restocking_damaged_goods(): void
    {
        [$transactionId, $product, $stockBeforeSale] = $this->paidProductTransaction('081290000076');
        $cash = $this->paymentMethod('CASH');
        $transactionItem = \DB::table('transaction_items')
            ->where('transaction_id', $transactionId)
            ->where('item_type', 'product')
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->postJson("/operasional/penjualan/{$transactionId}/retur", [
                'items' => [[
                    'transaction_item_id' => $transactionItem->id,
                    'quantity' => '1.0000',
                    'restock' => false,
                ]],
                'payment_method_id' => $cash->id,
                'reason' => 'Barang rusak dan tidak layak dijual kembali.',
            ])
            ->assertCreated();

        $this->assertSame(
            $stockBeforeSale - 2,
            (float) \DB::table('products')->where('id', $product->id)->value('current_stock'),
        );
        $this->assertDatabaseHas('sales_return_items', ['restocked' => false]);
        $this->assertDatabaseMissing('stock_movements', ['source_type' => 'sales_return']);
    }

    public function test_cashier_product_order_persists_before_payment_and_survives_snapshot_refresh(): void
    {
        $treatment = $this->treatment('TRT-NAIL-GEL-HAND');
        $employee = $this->employee('EMP-SARI');
        $product = \DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();
        $cash = $this->paymentMethod('CASH');
        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '15:00', [
                ['employee_id' => $employee->id, 'role' => 'primary'],
            ]),
        ], ['phone' => '081290000072'])->assertCreated();
        $reservationId = (int) $reservation->json('id');

        $this->actingAs($this->cashier)
            ->postJson("/operasional/reservasi/{$reservationId}/produk", [
                'product_id' => $product->id,
                'quantity' => '2.0000',
            ])
            ->assertCreated()
            ->assertJsonPath('product_id', $product->id);

        $this->assertDatabaseHas('reservation_product_items', [
            'reservation_id' => $reservationId,
            'product_id' => $product->id,
            'quantity' => '2.0000',
        ]);
        $snapshot = $this->actingAs($this->cashier)->getJson('/operasional/data')->assertOk()->json();
        $reservationSnapshot = collect($snapshot['reservations'])->firstWhere('id', $reservationId);
        $this->assertSame($product->name, $reservationSnapshot['product_items'][0]['name']);
        $this->assertSame(2.0, (float) $reservationSnapshot['product_items'][0]['quantity']);

        $total = (int) $treatment->normal_price + ((int) $product->selling_price * 2);
        $payment = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => $total,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('total', $total);

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $payment->json('id'),
            'item_type' => 'product',
            'item_id' => $product->id,
            'quantity' => '2.0000',
        ]);
        $this->assertDatabaseMissing('reservation_product_items', ['reservation_id' => $reservationId]);
    }

    public function test_cashier_can_add_treatment_to_unpaid_reservation_and_it_is_invoiced(): void
    {
        $initialTreatment = $this->treatment('TRT-NAIL-GEL-HAND');
        $additionalTreatment = $this->treatment('TRT-FACIAL-BARRIER');
        $sari = $this->employee('EMP-SARI');
        $dita = $this->employee('EMP-DITA');
        $cash = $this->paymentMethod('CASH');

        $reservation = $this->createReservation($this->admin, [
            $this->item($initialTreatment->id, '09:00', [
                ['employee_id' => $sari->id, 'role' => 'primary'],
            ]),
        ], ['phone' => '081290000071'])->assertCreated();
        $reservationId = (int) $reservation->json('id');

        $this->actingAs($this->cashier)
            ->postJson("/operasional/reservasi/{$reservationId}/item", [
                'treatment_id' => $additionalTreatment->id,
                'start_time' => '10:30',
                'staff' => [['employee_id' => $dita->id, 'role' => 'primary']],
            ])
            ->assertCreated()
            ->assertJsonPath('treatment_name', $additionalTreatment->name);

        $this->assertSame(2, (int) \DB::table('reservation_items')->where('reservation_id', $reservationId)->count());
        $this->assertDatabaseHas('reservation_item_staff', ['employee_id' => $dita->id]);

        $total = (int) $initialTreatment->normal_price + (int) $additionalTreatment->normal_price;
        $payment = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => $total,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('total', $total);

        $this->assertSame(2, (int) \DB::table('transaction_items')->where('transaction_id', $payment->json('id'))->count());
        $this->actingAs($this->cashier)
            ->postJson("/operasional/reservasi/{$reservationId}/item", [
                'treatment_id' => $additionalTreatment->id,
                'start_time' => '12:00',
                'staff' => [['employee_id' => $dita->id, 'role' => 'primary']],
            ])
            ->assertUnprocessable();
    }

    public function test_products_page_uses_server_side_pagination_and_search(): void
    {
        $unitId = (int) DB::table('units')->value('id');
        $now = now();
        DB::table('products')->insert(collect(range(1, 25))->map(fn (int $number): array => [
            'code' => 'PAG-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'name' => 'Barang Pagination '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'category' => 'Uji Pagination',
            'purchase_unit_id' => $unitId,
            'usage_unit_id' => $unitId,
            'purchase_to_usage_factor' => 1,
            'current_stock' => 10,
            'minimum_stock' => 2,
            'selling_price' => 10000,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        $this->actingAs($this->admin)
            ->getJson('/operasional/produk?search=Barang%20Pagination&per_page=20&page=1')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('data.0.code', 'PAG-001');

        $this->actingAs($this->admin)
            ->getJson('/operasional/produk?search=Barang%20Pagination&per_page=20&page=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.code', 'PAG-021');
    }

    public function test_stock_history_page_and_export_controls_use_a_date_range(): void
    {
        $product = DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();
        $now = now();
        $movement = [
            'product_id' => $product->id,
            'unit_id' => $product->usage_unit_id,
            'type' => 'in',
            'quantity' => 1,
            'stock_before' => 10,
            'stock_after' => 11,
            'unit_cost' => null,
            'source_type' => 'manual_adjustment',
            'source_id' => null,
            'notes' => 'Pengujian filter tanggal',
            'created_by' => $this->admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('stock_movements')->insert([
            $movement + ['reference' => 'FILTER-LUAR', 'occurred_at' => '2031-01-10 10:00:00'],
            $movement + ['reference' => 'FILTER-DALAM', 'occurred_at' => '2031-02-10 12:30:00'],
        ]);

        $this->actingAs($this->admin)
            ->get('/')
            ->assertOk()
            ->assertSee('id="stock-history-from"', false)
            ->assertSee('id="stock-history-to"', false)
            ->assertSee('id="export-stock-history"', false);

        $this->actingAs($this->admin)
            ->getJson('/operasional/produk/riwayat?from=2031-02-01&to=2031-02-28&per_page=20&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'FILTER-DALAM')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['reference' => 'FILTER-LUAR']);

        $this->actingAs($this->admin)
            ->getJson('/operasional/produk/riwayat?from=2031-02-28&to=2031-02-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_products_can_be_imported_from_excel_with_row_level_feedback(): void
    {
        $headers = ['KODE PRODUK', 'NAMA PRODUK', 'KATEGORI', 'SATUAN', 'STOK AWAL', 'STOK MINIMUM', 'HARGA JUAL', 'STATUS', 'DESKRIPSI'];
        $rows = [
            ['IMP-SHAMPOO', 'Shampoo Import', 'Hair', 'ML', '125.5', '20', 45000, 'AKTIF', 'Diimpor dari Excel'],
            ['IMP-CLIP', 'Hair Clip Import', 'Hair', 'PCS', '0', '3', 15000, 'NONAKTIF', 'Tanpa stok awal'],
            ['PRD-HERBAL-DRINK', 'Produk Duplikat', 'Konsumsi', 'SACHET', '5', '1', 10000, 'AKTIF', 'Harus dilewati'],
            ['IMP-UNIT-BAD', 'Produk Unit Salah', 'Hair', 'BOTOL-TIDAK-ADA', '2', '1', 10000, 'AKTIF', 'Harus dilewati'],
        ];
        $workbook = app(SpreadsheetExportService::class)->make(
            'IMPORT PRODUK',
            'PENGUJIAN IMPORT PRODUK',
            $headers,
            $rows,
            [6],
        );

        $response = $this->actingAs($this->admin)
            ->post('/operasional/produk/import', [
                'file' => UploadedFile::fake()->createWithContent('produk.xlsx', $workbook),
            ])
            ->assertOk()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('skipped', 2)
            ->assertJsonCount(2, 'issues');

        $this->assertStringContainsString('2 produk berhasil diimpor', $response->json('message'));
        $this->assertDatabaseHas('products', [
            'code' => 'IMP-SHAMPOO',
            'name' => 'Shampoo Import',
            'current_stock' => '125.5000',
            'minimum_stock' => '20.0000',
            'selling_price' => 45000,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('products', [
            'code' => 'IMP-CLIP',
            'current_stock' => '0.0000',
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('products', ['code' => 'IMP-UNIT-BAD']);

        $importedProductId = DB::table('products')->where('code', 'IMP-SHAMPOO')->value('id');
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $importedProductId,
            'type' => 'in',
            'quantity' => '125.5000',
            'source_type' => 'opening_stock_import',
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'products.imported',
            'subject_type' => 'product',
        ]);

        $this->actingAs($this->cashier)
            ->post('/operasional/produk/import', [
                'file' => UploadedFile::fake()->createWithContent('produk.csv', implode(',', $headers)),
            ])
            ->assertForbidden();
    }

    public function test_admin_can_update_product_selling_price(): void
    {
        $product = \DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();

        $this->actingAs($this->admin)
            ->patchJson("/operasional/produk/{$product->id}/harga", ['selling_price' => 15000])
            ->assertOk()
            ->assertJsonPath('selling_price', 15000);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'selling_price' => 15000,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'product.price_updated',
            'subject_type' => 'product',
            'subject_id' => $product->id,
        ]);
    }

    public function test_admin_can_edit_product_master_data_including_unit(): void
    {
        $product = \DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();
        $unit = \DB::table('units')->where('code', 'PCS')->firstOrFail();

        $this->actingAs($this->admin)
            ->patchJson("/operasional/produk/{$product->id}", [
                'name' => 'Herbal Drink Botol',
                'category' => 'Konsumsi',
                'unit_id' => $unit->id,
                'minimum_stock' => '20',
                'selling_price' => 12000,
                'is_active' => true,
                'description' => 'Data master dikoreksi.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Data produk berhasil diperbarui.');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Herbal Drink Botol',
            'purchase_unit_id' => $unit->id,
            'usage_unit_id' => $unit->id,
            'minimum_stock' => '20.0000',
            'selling_price' => 12000,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'product.updated',
            'subject_type' => 'product',
            'subject_id' => $product->id,
        ]);
    }

    public function test_stock_entry_increases_current_product_stock(): void
    {
        $product = \DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();
        $stockBefore = (float) $product->current_stock;

        $this->actingAs($this->admin)
            ->patchJson("/operasional/produk/{$product->id}/stok", [
                'type' => 'masuk',
                'quantity' => '5',
                'source' => 'Stok masuk',
                'notes' => 'Barang baru diterima.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Stok berhasil diperbarui.');

        $this->assertSame(
            $stockBefore + 5,
            (float) \DB::table('products')->where('id', $product->id)->value('current_stock'),
        );
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'in',
            'quantity' => '5.0000',
            'source_type' => 'manual_adjustment',
        ]);
    }

    public function test_stock_reduction_requires_a_description_and_keeps_an_audit_trail(): void
    {
        $product = DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();
        $stockBefore = (float) $product->current_stock;

        $this->actingAs($this->admin)
            ->patchJson("/operasional/produk/{$product->id}/stok", [
                'type' => 'keluar',
                'quantity' => '2',
                'source' => 'Rusak',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('notes');

        $this->actingAs($this->admin)
            ->patchJson("/operasional/produk/{$product->id}/stok", [
                'type' => 'keluar',
                'quantity' => '2',
                'source' => 'Rusak',
                'notes' => 'Kemasan bocor saat pengecekan rak.',
            ])
            ->assertOk();

        $this->assertSame(
            $stockBefore - 2,
            (float) DB::table('products')->where('id', $product->id)->value('current_stock'),
        );
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'out',
            'quantity' => '2.0000',
            'source_type' => 'manual_adjustment',
        ]);
    }

    public function test_financial_reports_use_hpp_snapshots_and_exclude_capital_from_profit_loss(): void
    {
        Carbon::setTestNow('2033-05-10 10:00:00');
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $therapist = $this->employee('EMP-DITA');
        $product = DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();
        $cash = $this->paymentMethod('CASH');

        DB::table('products')->where('code', 'PRD-BARRIER-MASK')->update(['cost_price' => 10000]);
        DB::table('products')->where('id', $product->id)->update(['cost_price' => 4500, 'current_stock' => 10]);
        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '10:00', [
                ['employee_id' => $therapist->id, 'role' => 'primary'],
            ]),
        ], ['phone' => '081299900001'])->assertCreated();
        $reservationId = (int) $reservation->json('id');
        DB::table('reservation_items')->where('reservation_id', $reservationId)->update([
            'work_status' => 'finished',
            'finished_at' => now(),
        ]);

        $total = (int) $treatment->normal_price + ((int) $product->selling_price * 2);
        $checkout = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'product_items' => [['product_id' => $product->id, 'quantity' => '2']],
                'payments' => [['payment_method_id' => $cash->id, 'amount' => $total]],
            ])
            ->assertCreated();
        $transactionId = (int) $checkout->json('id');

        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transactionId,
            'item_type' => 'treatment',
            'unit_cost' => 10000,
            'cost_amount' => 10000,
        ]);
        $this->assertDatabaseHas('transaction_items', [
            'transaction_id' => $transactionId,
            'item_type' => 'product',
            'unit_cost' => 4500,
            'cost_amount' => 9000,
        ]);
        // Perubahan HPP setelah transaksi tidak boleh mengubah laporan transaksi ini.
        DB::table('products')->where('id', $product->id)->update(['cost_price' => 9000]);

        $this->actingAs($this->admin)
            ->postJson('/operasional/keuangan/arus-kas', [
                'type' => 'expense',
                'report_group' => 'operating',
                'category' => 'Biaya operasional',
                'description' => 'Pembelian air minum untuk pelanggan.',
                'amount' => 2000,
                'entry_date' => '2033-05-10',
            ])
            ->assertCreated();
        $this->actingAs($this->admin)
            ->postJson('/operasional/keuangan/arus-kas', [
                'type' => 'income',
                'report_group' => 'capital',
                'category' => 'Modal usaha',
                'description' => 'Tambahan modal pemilik.',
                'amount' => 50000,
                'entry_date' => '2033-05-10',
            ])
            ->assertCreated();

        $dashboard = $this->actingAs($this->admin)
            ->getJson('/operasional/data')
            ->assertOk()
            ->json('dashboard');

        $this->assertSame(115000, $dashboard['profit_loss_month']['sales_revenue']);
        $this->assertSame(19000, $dashboard['profit_loss_month']['hpp_total']);
        $this->assertSame(2000, $dashboard['profit_loss_month']['manual_expense']);
        $this->assertSame(0, $dashboard['profit_loss_month']['manual_income']);
        $this->assertSame(94000, $dashboard['profit_loss_month']['net_profit']);
        $this->assertSame(163000, $dashboard['balance_sheet']['cash']);

        $this->actingAs($this->admin)
            ->getJson('/operasional/keuangan/laporan?from=2033-05-10&to=2033-05-10&as_of=2033-05-10')
            ->assertOk()
            ->assertJsonPath('cash_flow.from', '2033-05-10')
            ->assertJsonPath('cash_flow.to', '2033-05-10')
            ->assertJsonPath('cash_flow.income', 50000)
            ->assertJsonPath('cash_flow.expense', 2000)
            ->assertJsonPath('profit_loss.net_profit', 94000)
            ->assertJsonPath('balance_sheet.as_of', '2033-05-10');
    }

    public function test_cashier_can_rate_each_therapist_and_dashboard_ranks_the_result(): void
    {
        Carbon::setTestNow('2033-06-14 11:00:00');
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $dita = $this->employee('EMP-DITA');
        $rani = $this->employee('EMP-RANI');
        $cash = $this->paymentMethod('CASH');
        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '10:00', [
                ['employee_id' => $dita->id, 'role' => 'primary'],
                ['employee_id' => $rani->id, 'role' => 'assistant'],
            ]),
        ], ['phone' => '081299900002'])->assertCreated();
        $reservationId = (int) $reservation->json('id');
        DB::table('reservation_items')->where('reservation_id', $reservationId)->update([
            'work_status' => 'finished',
            'finished_at' => now(),
        ]);

        $payment = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $reservationId,
                'payments' => [[
                    'payment_method_id' => $cash->id,
                    'amount' => (int) $treatment->normal_price,
                ]],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'therapists');
        $transactionId = (int) $payment->json('id');

        $this->actingAs($this->cashier)
            ->postJson("/operasional/penjualan/{$transactionId}/penilaian-therapist", [
                'ratings' => [['employee_id' => $dita->id, 'stars' => 5]],
            ])
            ->assertUnprocessable();

        $this->actingAs($this->cashier)
            ->postJson("/operasional/penjualan/{$transactionId}/penilaian-therapist", [
                'ratings' => [
                    ['employee_id' => $dita->id, 'stars' => 5, 'review' => 'Sangat telaten dan hasil facial memuaskan.'],
                    ['employee_id' => $rani->id, 'stars' => 1, 'review' => 'Perlu lebih teliti saat menyiapkan layanan.'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Penilaian therapist berhasil disimpan.');

        $this->assertDatabaseHas('therapist_ratings', [
            'transaction_id' => $transactionId,
            'employee_id' => $dita->id,
            'rating' => 'professional',
            'stars' => 5,
            'review' => 'Sangat telaten dan hasil facial memuaskan.',
        ]);
        $this->assertDatabaseHas('therapist_ratings', [
            'transaction_id' => $transactionId,
            'employee_id' => $rani->id,
            'rating' => 'poor',
            'stars' => 1,
            'review' => 'Perlu lebih teliti saat menyiapkan layanan.',
        ]);

        $summary = $this->actingAs($this->admin)
            ->getJson('/operasional/data')
            ->assertOk()
            ->json('dashboard.therapist_rating_summary_current_month');
        $this->assertSame('Dita', $summary[0]['name']);
        $this->assertSame(5, $summary[0]['average']);
        $this->assertSame(1, $summary[0]['review_count']);
        $this->assertSame('Sangat telaten dan hasil facial memuaskan.', $summary[0]['reviews'][0]['review']);
        $this->assertSame(5, $summary[0]['reviews'][0]['stars']);
        $this->assertNotEmpty($summary[0]['reviews'][0]['rated_at']);
        $this->assertSame('Rani', $summary[1]['name']);
        $this->assertSame(1, $summary[1]['average']);
    }

    public function test_admin_can_update_default_commission_for_a_treatment(): void
    {
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');

        $this->actingAs($this->admin)
            ->patchJson("/operasional/treatment/{$treatment->id}/komisi", [
                'default_commission_percent' => '12.5',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Komisi treatment berhasil diperbarui.');

        $this->assertDatabaseHas('treatments', [
            'id' => $treatment->id,
            'default_commission_percent' => '12.5000',
        ]);
    }

    public function test_treatment_commission_profile_can_be_customized_for_three_therapists(): void
    {
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $dita = $this->employee('EMP-DITA');
        $rani = $this->employee('EMP-RANI');
        $sari = $this->employee('EMP-SARI');

        $this->actingAs($this->admin)
            ->patchJson("/operasional/treatment/{$treatment->id}/komisi", [
                'default_commission_percent' => '5',
                'commission_profiles' => [[
                    'therapist_count' => 3,
                    'commission_percents' => ['2', '1.5', '1.5'],
                ]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('treatment_commission_splits', [
            'treatment_id' => $treatment->id,
            'therapist_count' => 3,
            'therapist_position' => 1,
            'commission_percent' => '2.0000',
        ]);
        $snapshot = $this->actingAs($this->admin)->getJson('/operasional/data')->assertOk()->json();
        $snapshotTreatment = collect($snapshot['treatments'])->firstWhere('id', $treatment->id);
        $snapshotProfile = collect($snapshotTreatment['commission_profiles'])->firstWhere('therapist_count', 3);
        $this->assertSame([2.0, 1.5, 1.5], array_map('floatval', $snapshotProfile['commission_percents']));

        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '14:00', [
                ['employee_id' => $dita->id, 'role' => 'primary'],
                ['employee_id' => $rani->id, 'role' => 'assistant'],
                ['employee_id' => $sari->id, 'role' => 'assistant'],
            ]),
        ], ['phone' => '081290000333'])->assertCreated();

        $item = DB::table('reservation_items')->where('reservation_id', $reservation->json('id'))->first();
        $staff = DB::table('reservation_item_staff')
            ->where('reservation_item_id', $item->id)
            ->get()
            ->keyBy('employee_id');

        $this->assertSame(4750, (int) $item->commission_amount);
        $this->assertEquals('2.0000', $staff[$dita->id]->commission_percent);
        $this->assertSame(1900, (int) $staff[$dita->id]->commission_amount);
        $this->assertEquals('1.5000', $staff[$rani->id]->commission_percent);
        $this->assertSame(1425, (int) $staff[$rani->id]->commission_amount);
        $this->assertEquals('1.5000', $staff[$sari->id]->commission_percent);
        $this->assertSame(1425, (int) $staff[$sari->id]->commission_amount);
        $this->assertSame((int) $item->commission_amount, (int) $staff->sum('commission_amount'));
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
        $this->assertSame(0, \DB::table('cash_entries')->whereIn(
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

    public function test_dashboard_exposes_week_month_and_year_revenue_trends(): void
    {
        $cash = $this->paymentMethod('CASH');

        Carbon::setTestNow('2026-06-15 10:00:00');
        [$juneReservationId, $juneTotal] = $this->finishedTwoItemReservation('081290000090');
        $this->actingAs($this->cashier)->postJson('/operasional/pembayaran', [
            'reservation_id' => $juneReservationId,
            'idempotency_key' => 'revenue-trend-june',
            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => $juneTotal,
            ]],
        ])->assertCreated();

        Carbon::setTestNow('2026-08-20 10:00:00');
        [$augustReservationId, $augustTotal] = $this->finishedTwoItemReservation('081290000091');
        $this->actingAs($this->cashier)->postJson('/operasional/pembayaran', [
            'reservation_id' => $augustReservationId,
            'idempotency_key' => 'revenue-trend-august',
            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => $augustTotal,
            ]],
        ])->assertCreated();

        $dashboard = $this->actingAs($this->admin)
            ->getJson('/operasional/data')
            ->assertOk()
            ->json('dashboard');

        $week = $dashboard['revenue_last_7_days'];
        $month = $dashboard['revenue_current_month'];
        $year = $dashboard['revenue_current_year'];

        $this->assertCount(7, $week);
        $this->assertCount(20, $month);
        $this->assertCount(8, $year);
        $this->assertSame('2026-08-17', $week[0]['date']);
        $this->assertSame('Sen', $week[0]['label']);
        $this->assertSame('2026-08-23', $week[6]['date']);
        $this->assertSame('Min', $week[6]['label']);
        $this->assertSame('2026-08-01', $month[0]['date']);
        $this->assertSame('2026-08-20', $month[19]['date']);
        $this->assertSame('Jan', $year[0]['label']);
        $this->assertSame('Agu', $year[7]['label']);
        $this->assertSame($augustTotal, $week[3]['total']);
        $this->assertSame($augustTotal, collect($month)->sum('total'));
        $this->assertSame($juneTotal, $year[5]['total']);
        $this->assertSame($augustTotal, $year[7]['total']);
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

    public function test_finance_manager_can_record_manual_cash_entry_and_see_it_in_history(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');

        $response = $this->actingAs($this->admin)
            ->postJson('/operasional/keuangan/arus-kas', [
                'type' => 'expense',
                'category' => 'Operasional',
                'description' => 'Belanja tisu dan air minum',
                'amount' => 75000,
                'entry_date' => '2026-08-13',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Arus kas berhasil dicatat.');

        $entryId = (int) $response->json('id');
        $this->assertDatabaseHas('cash_entries', [
            'id' => $entryId,
            'transaction_payment_id' => null,
            'type' => 'expense',
            'category' => 'Operasional',
            'amount' => 75000,
            'entry_date' => '2026-08-13',
            'status' => 'posted',
            'created_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'finance.cash_entry_created',
            'subject_type' => 'cash_entry',
            'subject_id' => $entryId,
            'user_id' => $this->admin->id,
        ]);

        $snapshot = $this->actingAs($this->admin)->getJson('/operasional/data')->assertOk()->json();
        $entry = collect($snapshot['cash_entries'])->firstWhere('id', $entryId);
        $this->assertSame('Belanja tisu dan air minum', $entry['description']);
        $this->assertSame('Admin Selesa', $entry['created_by_name']);
        $this->assertSame(-75000, (int) $snapshot['dashboard']['month_balance']);
    }

    public function test_cash_entry_requires_finance_manage_permission_and_positive_amount(): void
    {
        $payload = [
            'type' => 'income',
            'category' => 'Lain-lain',
            'description' => 'Pemasukan manual',
            'amount' => 0,
            'entry_date' => today()->toDateString(),
        ];

        $this->actingAs($this->marketing)
            ->postJson('/operasional/keuangan/arus-kas', $payload)
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson('/operasional/keuangan/arus-kas', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_checkout_generates_short_sequential_daily_invoice_numbers(): void
    {
        Carbon::setTestNow('2026-08-13 10:00:00');
        [$firstReservationId, $firstTotal] = $this->finishedTwoItemReservation('081290000071');
        [$secondReservationId, $secondTotal] = $this->finishedTwoItemReservation('081290000072');
        $paymentMethod = $this->paymentMethod('CASH');

        $first = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $firstReservationId,
                'payments' => [[
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $firstTotal,
                ]],
            ])
            ->assertCreated();
        $second = $this->actingAs($this->cashier)
            ->postJson('/operasional/pembayaran', [
                'reservation_id' => $secondReservationId,
                'payments' => [[
                    'payment_method_id' => $paymentMethod->id,
                    'amount' => $secondTotal,
                ]],
            ])
            ->assertCreated();

        $this->assertSame('INV20260813001', $first->json('number'));
        $this->assertSame('INV20260813002', $second->json('number'));
        $this->assertDatabaseHas('invoice_sequences', [
            'invoice_date' => '2026-08-13',
            'last_number' => 2,
        ]);
    }

    public function test_payment_settings_drive_cashier_options_dashboard_cards_and_invoice_prefix(): void
    {
        $this->actingAs($this->admin)->get('/pengaturan/penjualan')
            ->assertOk()
            ->assertSee('Prefix invoice')
            ->assertSee('INV20260814001')
            ->assertDontSee('INV-20260814-001');
        $this->actingAs($this->admin)->get('/pengaturan/bank')
            ->assertOk()
            ->assertSee('Daftar Bank')
            ->assertSee('BCA')
            ->assertDontSee('BANK-001');

        $this->actingAs($this->admin)
            ->post('/pengaturan/bank', [
                'source_name' => 'Mandiri',
                'account_name' => 'Selesa Salon',
                'account_number' => '1234567890',
                'is_active' => '1',
            ])
            ->assertRedirect(route('settings.payment-methods.index', 'bank'));

        $this->assertDatabaseHas('payment_methods', ['code' => 'BANK-002', 'name' => 'Mandiri']);

        $snapshot = $this->actingAs($this->admin)->getJson('/operasional/data')->assertOk()->json();
        $this->assertTrue(collect($snapshot['payment_methods'])->contains('name', 'Mandiri'));
        $bankCard = collect($snapshot['dashboard']['revenue_by_payment_method_today'])->firstWhere('name', 'Mandiri');
        $this->assertSame(0, (int) $bankCard['total']);

        $this->actingAs($this->admin)
            ->patch('/pengaturan/penjualan', ['invoice_prefix' => 'SLS-'])
            ->assertSessionHasErrors('invoice_prefix');

        $this->actingAs($this->admin)
            ->patch('/pengaturan/penjualan', ['invoice_prefix' => 'SLS'])
            ->assertSessionHas('success');

        Carbon::setTestNow('2026-08-13 10:00:00');
        [$reservationId, $total] = $this->finishedTwoItemReservation('081290000073');
        $response = $this->actingAs($this->cashier)->postJson('/operasional/pembayaran', [
            'reservation_id' => $reservationId,
            'payments' => [[
                'payment_method_id' => $this->paymentMethod('CASH')->id,
                'amount' => $total,
            ]],
        ])->assertCreated();

        $this->assertSame('SLS20260813001', $response->json('number'));
    }

    public function test_members_and_membership_events_can_be_managed_without_losing_history(): void
    {
        $member = $this->actingAs($this->admin)
            ->postJson('/operasional/member', [
                'name' => 'Member Kelola',
                'phone' => '081290000081',
                'email' => 'member.kelola@example.test',
            ])
            ->assertCreated();
        $memberId = (int) $member->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/operasional/member/{$memberId}", [
                'name' => 'Member Diperbarui',
                'phone' => '081290000082',
                'email' => 'member.baru@example.test',
            ])
            ->assertOk();
        $this->assertDatabaseHas('customers', [
            'id' => $memberId,
            'name' => 'Member Diperbarui',
            'is_member' => true,
        ]);

        $promotion = $this->actingAs($this->admin)
            ->postJson('/operasional/promo', [
                'name' => 'Promo Kelola',
                'discount_percent' => 12.5,
                'starts_at' => today()->toDateString(),
                'ends_at' => today()->addDays(7)->toDateString(),
                'members_only' => true,
                'is_active' => true,
                'description' => 'Promo untuk pengujian',
            ])
            ->assertCreated();
        $promotionId = (int) $promotion->json('id');

        $this->actingAs($this->admin)
            ->patchJson("/operasional/promo/{$promotionId}", [
                'name' => 'Promo Diperbarui',
                'discount_percent' => 15,
                'starts_at' => today()->toDateString(),
                'ends_at' => today()->addDays(14)->toDateString(),
                'members_only' => false,
                'is_active' => true,
                'description' => null,
            ])
            ->assertOk();
        $this->assertDatabaseHas('promotions', [
            'id' => $promotionId,
            'name' => 'Promo Diperbarui',
            'discount_percent' => '15.0000',
        ]);

        $this->actingAs($this->admin)->deleteJson("/operasional/member/{$memberId}")->assertOk();
        $this->actingAs($this->admin)->deleteJson("/operasional/promo/{$promotionId}")->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $memberId, 'is_member' => false]);
        $this->assertDatabaseMissing('promotions', ['id' => $promotionId]);
    }

    public function test_reservation_can_use_registered_member_without_accepting_client_customer_data(): void
    {
        $member = $this->actingAs($this->admin)
            ->postJson('/operasional/member', [
                'name' => 'Member Reservasi',
                'phone' => '081290000091',
            ])
            ->assertCreated();
        $memberId = (int) $member->json('id');
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $therapist = $this->employee('EMP-DITA');

        $reservation = $this->actingAs($this->admin)
            ->postJson('/operasional/reservasi', [
                'customer_type' => 'member',
                'member_id' => $memberId,
                'name' => 'Nama dari browser yang tidak boleh dipakai',
                'phone' => '000000000000',
                'date' => today()->addDay()->toDateString(),
                'source' => 'walk_in',
                'items' => [$this->item($treatment->id, '16:00', [[
                    'employee_id' => $therapist->id,
                    'role' => 'primary',
                ]])],
            ])
            ->assertCreated();

        $this->assertDatabaseHas('reservations', [
            'id' => (int) $reservation->json('id'),
            'customer_id' => $memberId,
        ]);
        $this->assertDatabaseHas('customers', [
            'id' => $memberId,
            'name' => 'Member Reservasi',
            'phone' => '081290000091',
        ]);
        $this->assertDatabaseMissing('customers', ['phone' => '000000000000']);
    }

    public function test_schedule_includes_preparation_and_rest_before_therapist_is_available(): void
    {
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $therapist = $this->employee('EMP-DITA');
        $date = today()->addDays(30)->toDateString();
        $first = $this->createReservation($this->admin, [
            $this->item($treatment->id, '10:00', [['employee_id' => $therapist->id, 'role' => 'primary']]),
        ], ['date' => $date, 'phone' => '081290000301'])->assertCreated();
        $item = \DB::table('reservation_items')->where('reservation_id', $first->json('id'))->first();

        $expectedEnd = Carbon::parse("{$date} 10:00:00")->addMinutes((int) $treatment->duration_minutes + 15);
        $this->assertSame($expectedEnd->format('Y-m-d H:i:s'), Carbon::parse($item->scheduled_end_at)->format('Y-m-d H:i:s'));
        $this->assertSame($expectedEnd->copy()->addMinutes(45)->format('Y-m-d H:i:s'), Carbon::parse($item->scheduled_ready_at)->format('Y-m-d H:i:s'));

        $this->createReservation($this->admin, [
            $this->item($treatment->id, $expectedEnd->copy()->addMinutes(30)->format('H:i'), [['employee_id' => $therapist->id, 'role' => 'primary']]),
        ], ['date' => $date, 'phone' => '081290000302'])->assertStatus(409);
    }

    public function test_therapist_off_day_blocks_new_reservations_and_manual_cashier_discount_is_allowed(): void
    {
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $therapist = $this->employee('EMP-DITA');
        $date = today()->addDays(31)->toDateString();
        $this->actingAs($this->admin)->putJson("/operasional/therapist-kehadiran/{$therapist->id}", [
            'date' => $date,
            'status' => 'off',
        ])->assertOk();
        $this->createReservation($this->admin, [
            $this->item($treatment->id, '10:00', [['employee_id' => $therapist->id, 'role' => 'primary']]),
        ], ['date' => $date, 'phone' => '081290000303'])->assertUnprocessable()->assertJsonValidationErrors('items');

        [$reservationId, $total] = $this->finishedTwoItemReservation('081290000304');
        $this->actingAs($this->cashier)->postJson('/operasional/pembayaran', [
            'reservation_id' => $reservationId,
            'manual_discount_percent' => '10',
            'payments' => [[
                'payment_method_id' => $this->paymentMethod('CASH')->id,
                'amount' => $total - (int) round($total * .1),
            ]],
        ])->assertCreated();
        $this->assertDatabaseHas('transactions', ['reservation_id' => $reservationId, 'discount_amount' => (int) round($total * .1)]);
    }

    public function test_therapist_attendance_returns_off_therapists_grouped_by_month_for_calendar_markers(): void
    {
        $dita = $this->employee('EMP-DITA');
        $rani = $this->employee('EMP-RANI');
        $sari = $this->employee('EMP-SARI');
        $month = today()->addMonth()->startOfMonth();
        $singleOffDate = $month->copy()->addDays(4)->toDateString();
        $multipleOffDate = $month->copy()->addDays(12)->toDateString();

        DB::table('employee_attendances')->insert([
            [
                'employee_id' => $dita->id,
                'attendance_date' => $singleOffDate,
                'status' => 'off',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_id' => $rani->id,
                'attendance_date' => $multipleOffDate,
                'status' => 'off',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_id' => $sari->id,
                'attendance_date' => $multipleOffDate,
                'status' => 'off',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/operasional/therapist-kehadiran?date={$singleOffDate}&month=".$month->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('month', $month->format('Y-m'))
            ->assertJsonPath("off_by_date.{$singleOffDate}.0.name", 'Dita')
            ->assertJsonCount(2, "off_by_date.{$multipleOffDate}");

        $this->assertSame(['Rani', 'Sari'], collect($response->json("off_by_date.{$multipleOffDate}"))->pluck('name')->all());
    }

    public function test_dashboard_summarizes_present_and_off_therapists_for_today(): void
    {
        $offTherapist = $this->employee('EMP-DITA');
        DB::table('employee_attendances')->insert([
            'employee_id' => $offTherapist->id,
            'attendance_date' => today()->toDateString(),
            'status' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $attendance = $this->actingAs($this->admin)
            ->getJson('/operasional/data')
            ->assertOk()
            ->json('dashboard.therapist_attendance_today');

        $this->assertCount(3, $attendance['present']);
        $this->assertCount(1, $attendance['off']);
        $this->assertSame('Dita', $attendance['off'][0]['name']);
        $this->assertSame('off', $attendance['off'][0]['status']);
        $this->assertFalse(collect($attendance['present'])->contains('name', 'Dita'));
    }

    public function test_activity_snapshot_includes_customer_detail_for_reservation_logs(): void
    {
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $therapist = $this->employee('EMP-DITA');
        $this->createReservation($this->admin, [
            $this->item($treatment->id, '10:00', [['employee_id' => $therapist->id, 'role' => 'primary']]),
        ], [
            'name' => 'Customer Log Aktivitas',
            'phone' => '081290000305',
        ])->assertCreated();

        $activity = collect($this->actingAs($this->admin)
            ->getJson('/operasional/data')
            ->assertOk()
            ->json('activities'))
            ->firstWhere('action', 'reservation.created');

        $this->assertNotNull($activity);
        $this->assertSame('Customer Log Aktivitas', $activity['reservation_customer_name']);
        $this->assertStringContainsString('Customer Log Aktivitas', $activity['description']);
    }

    public function test_therapist_availability_reports_when_a_busy_therapist_is_ready_again(): void
    {
        $treatment = $this->treatment('TRT-FACIAL-BARRIER');
        $therapist = $this->employee('EMP-DITA');
        $date = today()->addDays(20)->toDateString();
        $this->createReservation($this->admin, [
            $this->item($treatment->id, '10:00', [['employee_id' => $therapist->id, 'role' => 'primary']]),
        ], ['date' => $date, 'phone' => '081290000306'])->assertCreated();

        $availability = $this->actingAs($this->admin)
            ->getJson("/operasional/reservasi/terapis-tersedia?date={$date}&start_time=10%3A15&treatment_id={$treatment->id}")
            ->assertOk()
            ->json('employees');
        $dita = collect($availability)->firstWhere('id', $therapist->id);

        $this->assertFalse($dita['available']);
        $this->assertSame($date.' 12:00:00', $dita['conflicts'][0]['ready_at']);
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

    private function paidProductTransaction(string $phone): array
    {
        $treatment = $this->treatment('TRT-NAIL-GEL-HAND');
        $employee = $this->employee('EMP-SARI');
        $product = \DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();
        $cash = $this->paymentMethod('CASH');
        $stockBeforeSale = (float) $product->current_stock;
        $reservation = $this->createReservation($this->admin, [
            $this->item($treatment->id, '15:00', [
                ['employee_id' => $employee->id, 'role' => 'primary'],
            ]),
        ], ['phone' => $phone])->assertCreated();
        $reservationId = (int) $reservation->json('id');
        \DB::table('reservation_items')->where('reservation_id', $reservationId)->update([
            'work_status' => 'finished',
            'finished_at' => now(),
        ]);
        $total = (int) $treatment->normal_price + ((int) $product->selling_price * 2);
        $checkout = $this->actingAs($this->cashier)->postJson('/operasional/pembayaran', [
            'reservation_id' => $reservationId,
            'product_items' => [[
                'product_id' => $product->id,
                'quantity' => '2.0000',
            ]],
            'payments' => [[
                'payment_method_id' => $cash->id,
                'amount' => $total,
            ]],
        ])->assertCreated();

        return [(int) $checkout->json('id'), $product, $stockBeforeSale, $total];
    }
}
