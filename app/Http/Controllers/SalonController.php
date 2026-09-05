<?php

namespace App\Http\Controllers;

use App\Http\Exceptions\ReservationConflictException;
use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\StoreReservationItemRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\StoreSalesReturnRequest;
use App\Http\Requests\UpdateReservationItemStatusRequest;
use App\Http\Requests\UpdateReservationStatusRequest;
use App\Http\Services\ActivityLogger;
use App\Http\Services\CheckoutService;
use App\Http\Services\ProductSpreadsheetImportService;
use App\Http\Services\RemunerationReportService;
use App\Http\Services\ReservationService;
use App\Http\Services\SalesReturnService;
use App\Http\Services\SalonSnapshotService;
use App\Http\Services\SpreadsheetExportService;
use App\Http\Support\FixedPoint;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalonController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly CheckoutService $checkout,
        private readonly SalesReturnService $salesReturns,
        private readonly SalonSnapshotService $snapshots,
        private readonly SpreadsheetExportService $spreadsheets,
        private readonly ProductSpreadsheetImportService $productImports,
        private readonly RemunerationReportService $remuneration,
        private readonly ActivityLogger $logger,
    ) {}

    public function dashboard(Request $request)
    {
        $salonData = Schema::hasTable('reservations')
            ? $this->snapshots->forUser($request->user())
            : [];

        return view('dashboard', compact('salonData'));
    }

    public function data(Request $request): JsonResponse
    {
        if (! Schema::hasTable('reservations')) {
            return response()->json([]);
        }

        return response()->json($this->snapshots->forUser($request->user()));
    }

    public function financeReport(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $timezone = config('app.timezone');
        $today = CarbonImmutable::today($timezone);
        $from = isset($data['from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $timezone)
            : $today->startOfMonth();
        $to = isset($data['to'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $timezone)
            : $today;
        abort_if($from->greaterThan($to), 422, 'Tanggal awal tidak boleh melewati tanggal akhir.');
        $asOf = isset($data['as_of'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $data['as_of'], $timezone)
            : $to;

        return response()->json($this->snapshots->financeReport(
            $request->user(),
            $from,
            $to,
            $asOf,
        ));
    }

    public function remunerationReport(Request $request): JsonResponse
    {
        [$from, $to] = $this->remunerationRange($request);

        return response()->json($this->remuneration->report($request->user(), $from, $to));
    }

    public function exportRemuneration(Request $request): StreamedResponse
    {
        [$from, $to] = $this->remunerationRange($request);
        $report = $this->remuneration->report($request->user(), $from, $to);

        // The workbook follows the salon's manual recap: one monthly table for
        // commission/overtime, one income table, and one stock in-out table.
        // The selected range controls the source data; the commission grid still
        // shows the complete payroll month so it can be completed manually.
        $calendarStart = $to->startOfMonth();
        $calendarEnd = $to->endOfMonth();
        $dates = [];
        for ($date = $calendarStart; $date->lessThanOrEqualTo($calendarEnd); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }

        $commissionByEmployeeAndDate = collect($report['commission_details'])
            ->groupBy(fn (array $item): string => $item['employee_id'].'|'.$item['date']);
        $overtimeByEmployeeAndDate = collect($report['attendance'])
            ->where('status', 'overtime')
            ->groupBy(fn (array $item): string => $item['employee_id'].'|'.$item['date']);
        $commissionHeaders = ['NO', 'NAMA TERAPIS'];
        foreach ($dates as $date) {
            $day = CarbonImmutable::parse($date)->format('d');
            $commissionHeaders[] = $day.' KOMISI';
            $commissionHeaders[] = $day.' LEMBUR';
        }
        $commissionHeaders = [...$commissionHeaders, 'JUMLAH KOMISI', 'JUMLAH LEMBUR', 'JUMLAH ALL'];
        $commissionRows = collect($report['employees'])->values()->map(function (array $employee, int $index) use ($dates, $commissionByEmployeeAndDate, $overtimeByEmployeeAndDate): array {
            $dailyValues = [];
            $commission = 0;
            foreach ($dates as $date) {
                $dailyCommission = (int) $commissionByEmployeeAndDate
                    ->get($employee['employee_id'].'|'.$date, collect())
                    ->sum('commission');
                $dailyValues[] = $dailyCommission;
                $dailyValues[] = (int) $overtimeByEmployeeAndDate
                    ->get($employee['employee_id'].'|'.$date, collect())
                    ->sum('overtime_amount');
                $commission += $dailyCommission;
            }
            $overtime = (int) $employee['overtime'];

            return [
                $index + 1,
                $employee['employee_name'],
                ...$dailyValues,
                $commission,
                $overtime,
                $commission + $overtime,
            ];
        })->all();

        $incomeHeaders = [
            'NO', 'NAMA', 'POSISI', 'JHK', 'GP', 'PENDAPATAN',
            'LEMBUR/U.MAKAN HARI', 'LEMBUR/U.MAKAN JML', 'KOMISI',
            'BONUS TARGET', 'BONUS SERVICE/KEHADIRAN',
            'TUNJANGAN KEHADIRAN', 'TUNJANGAN LAIN2', 'PENDAPATAN KOTOR',
            'MANGKIR HARI', 'JML MANGKIR',
            'KETERLAMBATAN MENIT', 'JML MENIT',
            'KASBON', 'POT. LAIN2', 'JML POTONGAN', 'PENDAPATAN BERSIH',
        ];
        $incomeRows = collect($report['employees'])->values()->map(fn (array $employee, int $index): array => [
            $index + 1,
            $employee['employee_name'],
            $employee['position'] ?: '-',
            $employee['paid_work_days'],
            $employee['daily_rate'],
            $employee['base_salary'],
            $employee['overtime_days'],
            $employee['overtime'] + $employee['meal_allowance'],
            $employee['commission'],
            $employee['target_bonus'],
            $employee['service_bonus'] + $employee['attendance_bonus'] + $employee['bonus'],
            $employee['attendance_allowance'],
            $employee['other_allowance'] + $employee['tip_deposit'],
            $employee['gross_income'],
            $employee['absence_days'],
            $employee['absence_deduction'],
            $employee['late_minutes'],
            $employee['late_deduction'],
            $employee['cash_advance'],
            $employee['other_deduction'],
            $employee['total_deduction'],
            $employee['net_salary'],
        ])->all();

        $stockHeaders = [
            'NO', 'PRODUK', 'TGL STOK MASUK', 'JML PROD. MASUK', 'SATUAN',
            'BERAT GROSS PROD. MASUK', 'SATUAN', 'DOSIS PER CUST', 'SATUAN',
            'JML STOK DOSIS PER CUST', 'PAX', 'TGL STOK KELUAR', 'JAM STOK KELUAR',
            'STOK PROD. KELUAR PER CUST', 'PAX', 'JML KELUAR', 'SATUAN',
            'SISA STOK DOSIS', 'PAX', 'SISA STOK PROD', 'SATUAN', 'CUSTOMER', 'TERAPIS',
        ];
        $stockRows = collect($report['stock_table_rows'])->map(fn (array $row): array => [
            $row['number'],
            $row['product'],
            $row['incoming_date'],
            $row['incoming_quantity'],
            $row['purchase_unit'],
            $row['gross_quantity'],
            $row['gross_unit'],
            $row['dose'],
            $row['dose_unit'],
            $row['capacity'],
            $row['capacity'] !== null ? 'PAX' : null,
            $row['outgoing_date'],
            $row['outgoing_time'],
            $row['customers_served'],
            $row['customers_served'] !== null ? 'PAX' : null,
            $row['outgoing_quantity'],
            $row['outgoing_unit'],
            $row['remaining_capacity'],
            $row['remaining_capacity'] !== null ? 'PAX' : null,
            $row['stock_after'],
            $row['stock_unit'],
            $row['customer'],
            $row['therapists'],
        ])->all();

        // Slip is intentionally produced once for all saved remuneration data.
        // One workbook contains one worksheet per employee, so the owner does
        // not need to open a separate PDF or download one file per therapist.
        $exportedBy = $request->user()?->name ?: 'Manajer / Pemilik';
        $slipRows = collect($report['employees'])
            ->filter(fn (array $employee): bool => (bool) ($employee['has_payroll_input'] ?? false))
            ->values()
            ->map(fn (array $employee): array => [
                'employee_name' => $employee['employee_name'],
                'position' => $employee['position'] ?: 'Karyawan',
                'period_label' => $calendarStart->translatedFormat('F Y'),
                'period_code' => $calendarStart->translatedFormat('M y'),
                'printed_date' => now(config('app.timezone'))->translatedFormat('d F Y'),
                'approved_by' => $exportedBy,
                'paid_work_days' => $employee['paid_work_days'],
                'absence_days' => $employee['absence_days'],
                'late_minutes' => $employee['late_minutes'],
                'daily_rate' => $employee['daily_rate'],
                'base_salary' => $employee['base_salary'],
                'commission' => $employee['commission'],
                'overtime_days' => $employee['overtime_days'],
                'overtime' => $employee['overtime'],
                'meal_allowance' => $employee['meal_allowance'],
                'total_allowance' => $employee['total_allowance'],
                'total_bonus' => $employee['target_bonus'] + $employee['service_bonus'] + $employee['attendance_bonus'] + $employee['bonus'],
                'tip_deposit' => $employee['tip_deposit'],
                'gross_income' => $employee['gross_income'],
                'absence_deduction' => $employee['absence_deduction'],
                'late_rate_per_minute' => $employee['late_rate_per_minute'],
                'late_deduction' => $employee['late_deduction'],
                'cash_advance' => $employee['cash_advance'],
                'other_deduction' => $employee['other_deduction'],
                'total_deduction' => $employee['total_deduction'],
                'net_salary' => $employee['net_salary'],
            ])->all();

        $sheets = [
            [
                'name' => 'REKAP KOM-LEM',
                'headers' => $commissionHeaders,
                'rows' => $commissionRows,
                'currency_columns' => range(2, count($commissionHeaders) - 1),
                'table_only' => true,
            ],
            [
                'name' => 'REKAP PENDAPATAN',
                'headers' => $incomeHeaders,
                'rows' => $incomeRows,
                'currency_columns' => [4, 5, 7, 8, 9, 10, 11, 12, 13, 15, 17, 18, 19, 20, 21],
                'table_only' => true,
            ],
            [
                'name' => 'REKAP STOK IN-OUT',
                'headers' => $stockHeaders,
                'rows' => $stockRows,
                'table_only' => true,
            ],
            [
                'name' => 'SLIP GAJI',
                'rows' => $slipRows,
            ],
        ];

        return response()->streamDownload(function () use ($sheets): void {
            echo $this->spreadsheets->makeRemunerationTemplateWorkbook($sheets);
        }, 'rekap-remunerasi-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function legacyTableExportRemuneration(Request $request): StreamedResponse
    {
        [$from, $to] = $this->remunerationRange($request);
        $report = $this->remuneration->report($request->user(), $from, $to);
        $periodLabel = $from->translatedFormat('d M Y').' - '.$to->translatedFormat('d M Y');
        $dates = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $dates[] = $date->toDateString();
        }
        $commissionByEmployeeAndDate = collect($report['commission_details'])
            ->groupBy(fn (array $item): string => $item['employee_id'].'|'.$item['date']);
        $commissionHeaders = ['NO', 'NAMA TERAPIS'];
        foreach ($dates as $date) {
            $commissionHeaders[] = CarbonImmutable::parse($date)->format('d').' KOMISI';
        }
        $commissionHeaders = [...$commissionHeaders, 'JUMLAH KOMISI', 'LEMBUR INPUT', 'JUMLAH KOM-LEM'];
        $commissionRows = collect($report['employees'])->values()->map(function (array $employee, int $index) use ($dates, $commissionByEmployeeAndDate): array {
            $daily = collect($dates)->map(fn (string $date): int => (int) $commissionByEmployeeAndDate
                ->get($employee['employee_id'].'|'.$date, collect())
                ->sum('commission'))
                ->all();
            $commission = array_sum($daily);
            $overtime = (int) $employee['overtime'];

            return [$index + 1, $employee['employee_name'], ...$daily, $commission, $overtime, $commission + $overtime];
        })->all();
        $incomeHeaders = [
            'NO', 'NAMA', 'POSISI', 'JHK', 'GP / HARI', 'PENDAPATAN GP',
            'LEMBUR / U. MAKAN', 'KOMISI', 'BONUS TARGET', 'BONUS SERVICE', 'BONUS KEHADIRAN',
            'BONUS LAIN', 'TUNJANGAN KEHADIRAN', 'TUNJANGAN LAIN', 'TITIPAN TIP',
            'PENDAPATAN KOTOR', 'MANGKIR (HARI)', 'POT. MANGKIR', 'TELAT (MENIT)',
            'POT. TELAT', 'KASBON', 'POT. LAIN', 'JML POTONGAN', 'PENDAPATAN BERSIH',
        ];
        $incomeRows = collect($report['employees'])->values()->map(fn (array $employee, int $index): array => [
            $index + 1,
            $employee['employee_name'],
            $employee['position'] ?: '-',
            $employee['paid_work_days'],
            $employee['daily_rate'],
            $employee['base_salary'],
            $employee['overtime'] + $employee['meal_allowance'],
            $employee['commission'],
            $employee['target_bonus'],
            $employee['service_bonus'],
            $employee['attendance_bonus'],
            $employee['bonus'],
            $employee['attendance_allowance'],
            $employee['other_allowance'],
            $employee['tip_deposit'],
            $employee['gross_income'],
            $employee['absence_days'],
            $employee['absence_deduction'],
            $employee['late_minutes'],
            $employee['late_deduction'],
            $employee['cash_advance'],
            $employee['other_deduction'],
            $employee['total_deduction'],
            $employee['net_salary'],
        ])->all();
        $stockRows = collect($report['stock_movements'])->values()->map(fn (array $movement, int $index): array => [
            $index + 1,
            $movement['product_name'],
            in_array($movement['type'], ['in', 'adjustment'], true) ? $movement['date'] : '',
            in_array($movement['type'], ['in', 'adjustment'], true) ? $movement['quantity'] : '',
            $movement['unit'],
            $movement['date'],
            $movement['time'],
            $movement['type'] === 'out' ? $movement['quantity'] : '',
            $movement['unit'],
            $movement['stock_after'],
            $movement['unit'],
            $movement['customer_name'] ?: '-',
            $movement['therapists'] ?: '-',
            $movement['reference'] ?: '-',
            $movement['notes'] ?: '-',
        ])->all();
        $sheets = [
            [
                'name' => 'REKAP KOM-LEM',
                'title' => 'KOMISI & LEMBUR - '.$periodLabel,
                'headers' => $commissionHeaders,
                'rows' => $commissionRows,
                'currency_columns' => range(2, count($commissionHeaders) - 1),
            ],
            [
                'name' => 'REKAP PENDAPATAN',
                'title' => 'REKAP PENDAPATAN - '.$periodLabel,
                'headers' => $incomeHeaders,
                'rows' => $incomeRows,
                'currency_columns' => [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 17, 19, 20, 21, 22, 23],
            ],
            [
                'name' => 'REKAP STOK IN-OUT',
                'title' => 'REKAP STOK IN-OUT - '.$periodLabel,
                'headers' => ['NO', 'PRODUK', 'TGL STOK MASUK', 'JML MASUK', 'SATUAN', 'TGL STOK KELUAR', 'JAM KELUAR', 'STOK KELUAR', 'SATUAN', 'SISA STOK', 'SATUAN', 'CUSTOMER', 'TERAPIS', 'REFERENSI', 'CATATAN'],
                'rows' => $stockRows,
            ],
        ];
        foreach ($report['employees'] as $employee) {
            if (! $employee['has_payroll_input']) {
                continue;
            }
            $sheets[] = [
                'name' => 'SLIP '.mb_strtoupper(mb_substr($employee['employee_name'], 0, 24)),
                'title' => 'SLIP PENDAPATAN - '.$employee['employee_name'].' - '.$periodLabel,
                'headers' => ['KOMPONEN', 'NILAI'],
                'rows' => [
                    ['Gaji pokok', $employee['base_salary']],
                    ['Komisi', $employee['commission']],
                    ['Lembur + uang makan', $employee['overtime'] + $employee['meal_allowance']],
                    ['Tunjangan', $employee['total_allowance']],
                    ['Bonus', $employee['total_bonus']],
                    ['Titipan TIP', $employee['tip_deposit']],
                    ['Pendapatan kotor', $employee['gross_income']],
                    ['Potongan mangkir', -$employee['absence_deduction']],
                    ['Potongan keterlambatan', -$employee['late_deduction']],
                    ['Kasbon', -$employee['cash_advance']],
                    ['Potongan lain', -$employee['other_deduction']],
                    ['TOTAL GAJI DITERIMA', $employee['net_salary']],
                ],
                'currency_columns' => [1],
            ];
        }

        return response()->streamDownload(function () use ($sheets): void {
            echo $this->spreadsheets->makeWorkbook($sheets);
        }, 'rekap-remunerasi-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function legacyExportRemuneration(Request $request): StreamedResponse
    {
        [$from, $to] = $this->remunerationRange($request);
        $report = $this->remuneration->report($request->user(), $from, $to);
        $periodLabel = $from->translatedFormat('d M Y').' - '.$to->translatedFormat('d M Y');

        return response()->streamDownload(function () use ($report, $periodLabel): void {
            echo $this->spreadsheets->makeWorkbook([
                [
                    'name' => 'REKAP KARYAWAN',
                    'title' => 'REKAP REMUNERASI · '.$periodLabel,
                    'headers' => ['KARYAWAN', 'JABATAN', 'TREATMENT', 'KOMISI OTOMATIS', 'GAJI POKOK INPUT', 'BONUS INPUT', 'LEMBUR INPUT', 'TERLAMBAT (MENIT)', 'POTONGAN TERLAMBAT', 'POTONGAN LAIN', 'GAJI INPUT', 'STATUS'],
                    'rows' => collect($report['employees'])->map(fn (array $employee): array => [
                        $employee['employee_name'],
                        $employee['position'] ?: '-',
                        $employee['treatment_count'],
                        $employee['commission'],
                        $employee['base_salary'],
                        $employee['bonus'],
                        $employee['overtime'],
                        $employee['late_minutes'],
                        $employee['late_deduction'],
                        $employee['other_deduction'],
                        $employee['net_salary'],
                        match ($employee['status']) {
                            'ready' => 'Siap Excel',
                            'completed' => 'Selesai',
                            default => 'Belum dicek',
                        },
                    ])->all(),
                    'currency_columns' => [3, 4, 5, 6, 8, 9, 10],
                ],
                [
                    'name' => 'KOMISI',
                    'title' => 'RINCIAN KOMISI · '.$periodLabel,
                    'headers' => ['TANGGAL', 'KARYAWAN', 'INVOICE', 'PELANGGAN', 'TREATMENT', 'QTY', 'KOMISI'],
                    'rows' => collect($report['commission_details'])->map(fn (array $item): array => [
                        $item['date'], $item['employee_name'], $item['transaction_number'], $item['customer_name'], $item['treatment_name'], $item['quantity'], $item['commission'],
                    ])->all(),
                    'currency_columns' => [6],
                ],
                [
                    'name' => 'KEHADIRAN',
                    'title' => 'CATATAN KEHADIRAN · '.$periodLabel,
                    'headers' => ['TANGGAL', 'KARYAWAN', 'STATUS', 'CATATAN'],
                    'rows' => collect($report['attendance'])->map(fn (array $item): array => [
                        $item['date'], $item['employee_name'], $item['status'] === 'off' ? 'Libur' : 'Masuk', $item['notes'] ?: '-',
                    ])->all(),
                ],
                [
                    'name' => 'STOK IN OUT',
                    'title' => 'REKAP STOK MASUK-KELUAR · '.$periodLabel,
                    'headers' => ['TANGGAL', 'ARUS', 'PRODUK', 'JUMLAH', 'SATUAN', 'REFERENSI', 'CATATAN'],
                    'rows' => collect($report['stock_movements'])->map(fn (array $item): array => [
                        $item['date'], $item['type'] === 'in' ? 'Masuk' : 'Keluar', $item['product_name'], $item['quantity'], $item['unit'], $item['reference'] ?: '-', $item['notes'] ?: '-',
                    ])->all(),
                ],
                [
                    'name' => 'PENDAPATAN',
                    'title' => 'REKAP PENDAPATAN · '.$periodLabel,
                    'headers' => ['TANGGAL', 'JENIS', 'NOMOR', 'PELANGGAN', 'NOMINAL'],
                    'rows' => collect($report['sales'])->map(fn (array $item): array => [
                        $item['date'], $item['type'], $item['number'], $item['customer_name'], $item['amount'],
                    ])->all(),
                    'currency_columns' => [4],
                ],
            ]);
        }, 'rekap-remunerasi-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function legacyUpdateRemunerationStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => ['required', Rule::in(['pending', 'ready', 'completed'])],
        ]);
        [$from, $to] = $this->remunerationRange($request);
        $report = $this->remuneration->report($request->user(), $from, $to);
        $employee = collect($report['employees'])->firstWhere('employee_id', (int) $data['employee_id']);
        abort_unless($employee, 422, 'Karyawan tidak aktif atau tidak ditemukan.');

        $now = now();
        DB::table('remuneration_period_checks')->updateOrInsert(
            [
                'employee_id' => (int) $data['employee_id'],
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
            ],
            [
                'status' => $data['status'],
                'snapshot' => $data['status'] === 'completed'
                    ? json_encode(['period' => $report['period'], 'employee' => $employee], JSON_THROW_ON_ERROR)
                    : null,
                'status_updated_by' => $request->user()->id,
                'status_updated_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
        $this->logger->log(
            $request,
            'remuneration.status_updated',
            'employee',
            (int) $data['employee_id'],
            'Memperbarui status rekap remunerasi '.$employee['employee_name'],
            ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'status' => $data['status']],
        );

        return response()->json(['message' => 'Status rekap remunerasi diperbarui.']);
    }

    public function updateRemunerationSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payday_day' => ['required', 'integer', 'min:1', 'max:31'],
            'cutoff_day' => ['required', 'integer', 'min:1', 'max:31'],
        ]);
        $now = now();
        foreach (['remuneration_payday_day' => $data['payday_day'], 'remuneration_cutoff_day' => $data['cutoff_day']] as $key => $value) {
            DB::table('sale_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => (string) $value, 'updated_at' => $now, 'created_at' => $now],
            );
        }
        $this->logger->log($request, 'remuneration.schedule_updated', 'sale_setting', null, 'Memperbarui penanda tanggal remunerasi', $data);

        return response()->json(['message' => 'Tanggal gajian dan cutoff disimpan.']);
    }

    public function salesPage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->snapshots->salesPage(
            $request->user(),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 20),
            $data['search'] ?? null,
            $data['payment_method'] ?? null,
        ));
    }

    public function salesReturnsPage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->snapshots->salesReturnsPage(
            $request->user(),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 20),
            $data['search'] ?? null,
            $data['payment_method'] ?? null,
        ));
    }

    public function membersPage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->snapshots->membersPage(
            $request->user(),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 10),
            $data['search'] ?? null,
        ));
    }

    public function productsPage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->snapshots->productsPage(
            $request->user(),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 20),
            $data['search'] ?? null,
        ));
    }

    public function stockHistoryPage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? today()->toDateString();

        if ($to < $from) {
            throw ValidationException::withMessages([
                'to' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
            ]);
        }

        return response()->json($this->snapshots->stockMovementsPage(
            $request->user(),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 20),
            $from,
            $to,
        ));
    }

    public function importProducts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120'],
        ]);
        $file = $data['file'];
        $extension = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages([
                'file' => ['Format file harus .xlsx atau .csv.'],
            ]);
        }

        $path = $file->getRealPath();
        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => ['File unggahan tidak dapat dibaca.'],
            ]);
        }

        $result = $this->productImports->import($path, $extension, $request->user()?->id);
        if ($result['imported'] > 0) {
            $this->logger->log(
                $request,
                'products.imported',
                'product',
                null,
                "Mengimpor {$result['imported']} produk dari Excel",
                ['imported' => $result['imported'], 'skipped' => $result['skipped']],
            );
        }

        $message = "{$result['imported']} produk berhasil diimpor.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} baris dilewati.";
        }

        return response()->json([...$result, 'message' => $message]);
    }

    public function exportSchedule(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $date = $data['date'] ?? today()->toDateString();
        $items = DB::table('reservation_items as item')
            ->join('reservations as reservation', 'reservation.id', '=', 'item.reservation_id')
            ->join('customers as customer', 'customer.id', '=', 'reservation.customer_id')
            ->leftJoin('transactions as transaction', 'transaction.reservation_id', '=', 'reservation.id')
            ->where('reservation.reservation_date', $date)
            ->where('reservation.status', '!=', 'cancelled')
            ->where('item.work_status', '!=', 'cancelled')
            ->orderBy('item.scheduled_start_at')
            ->orderBy('item.id')
            ->get([
                'item.id',
                'item.reservation_id',
                'item.treatment_name',
                'item.sort_order',
                'item.scheduled_start_at',
                'item.scheduled_end_at',
                'item.scheduled_ready_at',
                'item.unit_price',
                'item.commission_amount',
                'customer.name as customer_name',
                'transaction.status as payment_status',
            ]);
        $assignments = DB::table('reservation_item_staff as assignment')
            ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->whereIn('assignment.reservation_item_id', $items->pluck('id'))
            ->orderByRaw("CASE WHEN assignment.role = 'primary' THEN 0 ELSE 1 END")
            ->orderBy('employee.name')
            ->get([
                'assignment.reservation_item_id',
                'assignment.employee_id',
                'assignment.role',
                'assignment.commission_amount',
                'employee.name',
            ]);
        $staffByItem = $assignments->groupBy('reservation_item_id');
        $payments = DB::table('transaction_payments as payment')
            ->join('transactions as transaction', 'transaction.id', '=', 'payment.transaction_id')
            ->join('payment_methods as method', 'method.id', '=', 'payment.payment_method_id')
            ->where('payment.status', 'confirmed')
            ->where('transaction.status', 'paid')
            ->whereIn('transaction.reservation_id', $items->pluck('reservation_id')->unique())
            ->get([
                'transaction.reservation_id',
                'method.name as payment_method_name',
                'payment.amount',
            ]);
        $paymentsByReservation = $payments->groupBy('reservation_id');
        $reservationTotals = $items->groupBy('reservation_id')->map(
            fn ($reservationItems): int => (int) $reservationItems->sum('unit_price')
        );
        $orderedItems = $items
            ->groupBy('reservation_id')
            ->sortBy(fn ($reservationItems) => $reservationItems->min('scheduled_start_at'))
            ->flatMap(fn ($reservationItems) => $reservationItems->sortBy(
                fn (object $item): string => str_pad((string) $item->sort_order, 6, '0', STR_PAD_LEFT)
                    .'-'.str_pad((string) $item->id, 20, '0', STR_PAD_LEFT)
            ))
            ->values();

        $rows = $orderedItems->map(function (object $item) use ($staffByItem, $paymentsByReservation, $reservationTotals): array {
            $therapists = collect($staffByItem->get($item->id, []))->pluck('name')->unique()->join(', ');
            $paymentMethods = collect($paymentsByReservation->get($item->reservation_id, []))
                ->pluck('payment_method_name')
                ->unique()
                ->join(' + ');

            return [
                'reservation_id' => (int) $item->reservation_id,
                'customer_name' => $item->customer_name,
                'treatment_name' => $item->treatment_name,
                'start_time' => $item->scheduled_start_at,
                'end_time' => $item->scheduled_end_at,
                'ready_time' => $item->scheduled_ready_at,
                'therapists' => $therapists ?: '-',
                'payment' => $paymentMethods ?: ($item->payment_status === 'paid' ? 'LUNAS' : 'BELUM DIBAYAR'),
                'reservation_total' => (int) $reservationTotals->get($item->reservation_id, 0),
                'unit_price' => (int) $item->unit_price,
                'commission_amount' => (int) $item->commission_amount,
            ];
        })->all();
        $staffSummary = $assignments
            ->groupBy('employee_id')
            ->map(fn ($employeeAssignments): array => [
                'name' => $employeeAssignments->first()->name,
                'commission' => (int) $employeeAssignments->sum('commission_amount'),
                'overtime' => 0,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
        $paymentSummary = $payments
            ->groupBy('payment_method_name')
            ->map(fn ($methodPayments, string $method): array => [
                'method' => mb_strtoupper($method),
                'amount' => (int) $methodPayments->sum('amount'),
            ])
            ->sortBy('method', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $filename = 'jadwal-selesa-'.str_replace('-', '', $date).'.xlsx';
        $scheduleDate = CarbonImmutable::parse($date);

        return response()->streamDownload(function () use ($scheduleDate, $rows, $staffSummary, $paymentSummary): void {
            echo $this->spreadsheets->makeDailySchedule(
                $scheduleDate->translatedFormat('j F Y'),
                ucfirst($scheduleDate->translatedFormat('l, j F Y')),
                $rows,
                $staffSummary,
                $paymentSummary,
            );
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportStockHistory(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = $data['from'] ?? now()->startOfMonth()->toDateString();
        $to = $data['to'] ?? today()->toDateString();
        $movements = DB::table('stock_movements as movement')
            ->join('products as product', 'product.id', '=', 'movement.product_id')
            ->join('units as movementUnit', 'movementUnit.id', '=', 'movement.unit_id')
            ->join('units as purchaseUnit', 'purchaseUnit.id', '=', 'product.purchase_unit_id')
            ->join('units as usageUnit', 'usageUnit.id', '=', 'product.usage_unit_id')
            ->leftJoin('transactions as transaction', function ($join): void {
                $join->on('transaction.id', '=', 'movement.source_id')
                    ->whereIn('movement.source_type', ['transaction', 'transaction_sale']);
            })
            ->leftJoin('reservations as reservation', 'reservation.id', '=', 'transaction.reservation_id')
            ->leftJoin('customers as customer', 'customer.id', '=', 'reservation.customer_id')
            ->whereBetween('movement.occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->orderBy('movement.occurred_at')
            ->orderBy('movement.id')
            ->get([
                'movement.id',
                'movement.product_id',
                'movement.type',
                'movement.quantity',
                'movement.stock_before',
                'movement.stock_after',
                'movement.source_type',
                'movement.reference',
                'movement.notes',
                'movement.occurred_at',
                'product.name as product_name',
                'product.purchase_to_usage_factor',
                'movementUnit.code as movement_unit_code',
                'purchaseUnit.code as purchase_unit_code',
                'usageUnit.code as usage_unit_code',
                'reservation.id as reservation_id',
                'customer.name as customer_name',
            ]);
        $recipeDosesByProduct = DB::table('treatment_product_recipes as recipe')
            ->join('units as unit', 'unit.id', '=', 'recipe.unit_id')
            ->whereIn('recipe.product_id', $movements->pluck('product_id')->unique())
            ->get(['recipe.product_id', 'recipe.quantity', 'unit.code as unit_code'])
            ->groupBy('product_id');
        $reservationIds = $movements->pluck('reservation_id')->filter()->unique()->values();
        $therapistsByReservation = $reservationIds->isEmpty()
            ? collect()
            : DB::table('reservation_item_staff as assignment')
                ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
                ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
                ->whereIn('item.reservation_id', $reservationIds)
                ->where('assignment.role', 'primary')
                ->orderBy('employee.name')
                ->get(['item.reservation_id', 'employee.name'])
                ->groupBy('reservation_id');

        $rows = $movements->values()->map(function (object $movement, int $index) use ($recipeDosesByProduct, $therapistsByReservation): array {
            $quantity = (float) $movement->quantity;
            $stockBefore = (float) $movement->stock_before;
            $stockAfter = (float) $movement->stock_after;
            $incoming = $movement->type === 'in'
                || ($movement->type === 'adjustment' && $stockAfter >= $stockBefore);
            $outgoing = $movement->type === 'out'
                || ($movement->type === 'adjustment' && $stockAfter < $stockBefore);
            $factor = max(0.0001, (float) $movement->purchase_to_usage_factor);
            $recipeDoses = collect($recipeDosesByProduct->get($movement->product_id, []))
                ->map(fn (object $recipe): float => (float) $recipe->quantity)
                ->unique(fn (float $dose): string => number_format($dose, 4, '.', ''))
                ->values();
            $canonicalDose = $recipeDoses->count() === 1 ? (float) $recipeDoses->first() : null;
            $dose = $outgoing && $movement->customer_name
                ? $quantity
                : $canonicalDose;
            $capacityBase = $incoming ? $stockAfter : $stockBefore;
            $capacity = $dose && $dose > 0 ? $capacityBase / $dose : null;
            $customersServed = $outgoing && $dose && $dose > 0 ? $quantity / $dose : null;
            $remainingCapacity = $dose && $dose > 0 ? $stockAfter / $dose : null;
            $occurredAt = CarbonImmutable::parse($movement->occurred_at);

            return [
                'number' => $index + 1,
                'product' => mb_strtoupper($movement->product_name),
                'incoming_date' => $incoming ? $occurredAt->format('Y-m-d') : null,
                'incoming_quantity' => $incoming ? $quantity / $factor : null,
                'purchase_unit' => $incoming ? mb_strtoupper($movement->purchase_unit_code) : null,
                'gross_quantity' => $incoming ? $factor : null,
                'gross_unit' => $incoming ? mb_strtoupper($movement->usage_unit_code) : null,
                'dose' => $dose,
                'dose_unit' => $dose ? mb_strtoupper($movement->usage_unit_code) : null,
                'capacity' => $capacity,
                'outgoing_date' => $outgoing ? $occurredAt->format('Y-m-d') : null,
                'outgoing_time' => $outgoing ? $occurredAt->format('H:i') : null,
                'customers_served' => $customersServed,
                'outgoing_quantity' => $outgoing ? $quantity : null,
                'outgoing_unit' => $outgoing ? mb_strtoupper($movement->movement_unit_code) : null,
                'remaining_capacity' => $remainingCapacity,
                'stock_after' => $stockAfter,
                'stock_unit' => mb_strtoupper($movement->movement_unit_code),
                'customer' => $outgoing ? ($movement->customer_name ?: null) : null,
                'therapists' => $outgoing
                    ? (collect($therapistsByReservation->get($movement->reservation_id, []))->pluck('name')->unique()->join(', ') ?: null)
                    : null,
            ];
        })->all();

        return response()->streamDownload(function () use ($rows): void {
            echo $this->spreadsheets->makeStockInOut($rows);
        }, "rekap-stok-in-out-{$from}-{$to}.xlsx", [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function storeReservation(StoreReservationRequest $request): JsonResponse
    {
        try {
            $reservation = $this->reservations->create($request->validated(), $request);
        } catch (ReservationConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'schedule_conflict',
                'can_override' => $exception->canOverride,
                'requires_reason' => true,
                'override_permission' => 'reservations.override_conflict',
                'conflicts' => $exception->conflicts,
            ], 409);
        }

        return response()->json([
            'message' => 'Reservasi berhasil dibuat.',
            ...$reservation,
        ], 201);
    }

    public function availableTherapists(Request $request): JsonResponse
    {
        if (! $request->filled('start_time') && $request->filled('time')) {
            $request->merge(['start_time' => $request->input('time')]);
        }

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'treatment_id' => ['required', 'integer', 'exists:treatments,id'],
        ]);
        $employees = $this->reservations->availability($data['date'], $data['start_time'], (int) $data['treatment_id']);

        return response()->json([
            'employees' => $employees,
            'therapists' => $employees,
        ]);
    }

    public function therapistAttendance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        $month = $data['month'] ?? substr($data['date'], 0, 7);
        $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        $attendance = DB::table('employees as employee')
            ->leftJoin('employee_attendances as attendance', function ($join) use ($data): void {
                $join->on('attendance.employee_id', '=', 'employee.id')
                    ->where('attendance.attendance_date', '=', $data['date']);
            })
            ->where('employee.active', true)
            ->where('employee.is_service_provider', true)
            ->orderBy('employee.name')
            ->get([
                'employee.id as employee_id',
                'employee.name',
                'employee.specialty',
                'attendance.status',
                'attendance.overtime_amount',
                'attendance.notes',
            ])
            ->map(fn (object $employee): array => [
                'employee_id' => (int) $employee->employee_id,
                'name' => $employee->name,
                'specialty' => $employee->specialty,
                // Belum diatur berarti dianggap masuk, sehingga tidak mengubah
                // alur reservasi yang sudah berjalan.
                'status' => $employee->status ?: 'present',
                'overtime_amount' => (int) ($employee->overtime_amount ?? 0),
                'notes' => $employee->notes,
            ])
            ->values();

        $offByDate = DB::table('employee_attendances as attendance')
            ->join('employees as employee', 'employee.id', '=', 'attendance.employee_id')
            ->where('employee.active', true)
            ->where('employee.is_service_provider', true)
            ->where('attendance.status', 'off')
            ->whereBetween('attendance.attendance_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->orderBy('attendance.attendance_date')
            ->orderBy('employee.name')
            ->get([
                'attendance.attendance_date',
                'employee.id as employee_id',
                'employee.name',
            ])
            ->groupBy('attendance_date')
            ->map(fn ($attendances) => $attendances->map(fn (object $attendance): array => [
                'employee_id' => (int) $attendance->employee_id,
                'name' => $attendance->name,
            ])->values())
            ->all();

        return response()->json([
            'date' => $data['date'],
            'month' => $month,
            'therapists' => $attendance,
            'present' => $attendance->whereIn('status', ['present', 'overtime'])->values(),
            'off' => $attendance->where('status', 'off')->values(),
            'off_by_date' => $offByDate,
        ]);
    }

    public function updateTherapistAttendance(Request $request, int $employee): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['present', 'off', 'overtime'])],
            'overtime_amount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $therapist = DB::table('employees')
            ->where('id', $employee)
            ->where('active', true)
            ->where('is_service_provider', true)
            ->first(['id', 'name']);
        abort_unless($therapist, 404, 'Therapist aktif tidak ditemukan.');

        if ($data['status'] === 'off') {
            $hasSchedule = DB::table('reservation_item_staff as staff')
                ->join('reservation_items as item', 'item.id', '=', 'staff.reservation_item_id')
                ->join('reservations as reservation', 'reservation.id', '=', 'item.reservation_id')
                ->where('staff.employee_id', $employee)
                ->where('reservation.reservation_date', $data['date'])
                ->whereNotIn('reservation.status', ['cancelled', 'completed'])
                ->whereNotIn('item.work_status', ['cancelled', 'finished'])
                ->exists();
            abort_if($hasSchedule, 422, 'Therapist masih memiliki jadwal aktif; pindahkan atau batalkan jadwal terlebih dahulu.');
        }

        $now = now();
        DB::table('employee_attendances')->updateOrInsert(
            ['employee_id' => $employee, 'attendance_date' => $data['date']],
            [
                'status' => $data['status'],
                'overtime_amount' => $data['status'] === 'overtime' ? (int) ($data['overtime_amount'] ?? 0) : 0,
                'notes' => ($data['notes'] ?? null) ? trim($data['notes']) : null,
                'updated_by' => $request->user()?->id,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
        $this->logger->log(
            $request,
            'therapist.attendance_updated',
            'employee',
            $employee,
            "Menandai {$therapist->name} sebagai ".match ($data['status']) {
                'off' => 'libur',
                'overtime' => 'lembur',
                default => 'masuk',
            },
            ['date' => $data['date'], 'status' => $data['status'], 'overtime_amount' => (int) ($data['overtime_amount'] ?? 0)],
        );

        return response()->json(['message' => 'Status kehadiran therapist diperbarui.']);
    }

    public function storeReservationItem(StoreReservationItemRequest $request, int $reservation): JsonResponse
    {
        try {
            $item = $this->reservations->addItem($reservation, $request->validated(), $request);
        } catch (ReservationConflictException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'schedule_conflict',
                'can_override' => false,
                'conflicts' => $exception->conflicts,
            ], 409);
        }

        return response()->json([
            'message' => 'Treatment tambahan masuk ke reservasi dan invoice.',
            ...$item,
        ], 201);
    }

    public function storeReservationProduct(Request $request, int $reservation): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
        ]);

        $item = $this->reservations->addProductItem($reservation, $data, $request);

        return response()->json([
            'message' => 'Produk ditambahkan ke pesanan pelanggan.',
            ...$item,
        ], 201);
    }

    public function destroyReservationProduct(Request $request, int $reservation, int $product): JsonResponse
    {
        $this->reservations->removeProductItem($reservation, $product, $request);

        return response()->json(['message' => 'Produk dihapus dari pesanan pelanggan.']);
    }

    public function updateReservation(UpdateReservationStatusRequest $request, int $id): JsonResponse
    {
        $result = $this->reservations->updateHeaderStatus(
            $id,
            $request->validated('status'),
            $request->validated('reason'),
            $request,
        );

        return response()->json(['message' => 'Status reservasi diperbarui.', ...$result]);
    }

    public function updateReservationItemStatus(
        UpdateReservationItemStatusRequest $request,
        int $reservation,
        int $item,
    ): JsonResponse {
        $result = $this->reservations->updateItemStatus(
            $reservation,
            $item,
            $request->validated('status'),
            $request->validated('reason'),
            $request,
        );

        return response()->json(['message' => 'Status pengerjaan diperbarui.', ...$result]);
    }

    public function storeEmployee(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:employees,code'],
            'name' => ['required', 'string', 'max:150'],
            'position' => ['nullable', 'string', 'max:100'],
            'specialty' => ['nullable', 'string', 'max:150'],
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'unique:employees,user_id'],
            'is_service_provider' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $now = now();
        $id = DB::table('employees')->insertGetId([
            'code' => $data['code'] ?? 'EMP-'.Str::upper(Str::random(8)),
            'name' => $data['name'],
            'position' => $data['position'] ?? null,
            'specialty' => $data['specialty'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'is_service_provider' => (bool) ($data['is_service_provider'] ?? true),
            'active' => (bool) ($data['active'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->logger->log($request, 'employee.created', 'employee', $id, "Menambahkan pegawai {$data['name']}");

        return response()->json(['message' => 'Pegawai berhasil ditambahkan.', 'id' => $id], 201);
    }

    public function updateEmployee(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('employees')->where('id', $id)->exists(), 404, 'Pegawai tidak ditemukan.');
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('employees', 'code')->ignore($id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'position' => ['sometimes', 'nullable', 'string', 'max:100'],
            'specialty' => ['sometimes', 'nullable', 'string', 'max:150'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id', Rule::unique('employees', 'user_id')->ignore($id)],
            'is_service_provider' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if ($data === []) {
            throw ValidationException::withMessages(['employee' => ['Tidak ada perubahan yang dikirim.']]);
        }

        DB::table('employees')->where('id', $id)->update([...$data, 'updated_at' => now()]);
        $this->logger->log($request, 'employee.updated', 'employee', $id, 'Memperbarui data pegawai', ['fields' => array_keys($data)]);

        return response()->json(['message' => 'Pegawai berhasil diperbarui.', 'id' => $id]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        if (! $request->has('current_stock') && $request->has('stock')) {
            $request->merge(['current_stock' => $request->input('stock')]);
        }

        if ($request->filled('unit') && ! $request->filled('usage_unit_id')) {
            $unitId = DB::table('units')
                ->whereRaw('LOWER(code) = ?', [mb_strtolower((string) $request->input('unit'))])
                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower((string) $request->input('unit'))])
                ->value('id');
            if ($unitId) {
                $request->merge(['usage_unit_id' => $unitId, 'purchase_unit_id' => $unitId]);
            }
        }

        if (! $request->filled('purchase_unit_id') && $request->filled('usage_unit_id')) {
            $request->merge(['purchase_unit_id' => $request->input('usage_unit_id')]);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:products,code'],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'purchase_unit_id' => ['required', 'integer', 'exists:units,id'],
            'usage_unit_id' => ['required', 'integer', 'exists:units,id'],
            'purchase_to_usage_factor' => ['nullable', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'current_stock' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'minimum_stock' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'selling_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $stock = FixedPoint::parse($data['current_stock'], FixedPoint::STOCK_SCALE);
        $conversionFactor = FixedPoint::parse($data['purchase_to_usage_factor'] ?? '1', FixedPoint::STOCK_SCALE);
        abort_if($conversionFactor === 0, 422, 'Faktor konversi satuan harus lebih dari nol.');
        $now = now();

        $id = DB::transaction(function () use ($data, $stock, $conversionFactor, $request, $now): int {
            $id = DB::table('products')->insertGetId([
                'code' => $data['code'] ?? 'PRD-'.Str::upper(Str::random(8)),
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'purchase_unit_id' => $data['purchase_unit_id'],
                'usage_unit_id' => $data['usage_unit_id'],
                'purchase_to_usage_factor' => FixedPoint::format($conversionFactor, FixedPoint::STOCK_SCALE),
                'current_stock' => FixedPoint::format($stock, FixedPoint::STOCK_SCALE),
                'minimum_stock' => FixedPoint::format(
                    FixedPoint::parse($data['minimum_stock'], FixedPoint::STOCK_SCALE),
                    FixedPoint::STOCK_SCALE,
                ),
                'selling_price' => $data['selling_price'],
                'cost_price' => (int) ($data['cost_price'] ?? 0),
                'is_active' => true,
                'description' => $data['description'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($stock > 0) {
                DB::table('stock_movements')->insert([
                    'product_id' => $id,
                    'unit_id' => $data['usage_unit_id'],
                    'type' => 'in',
                    'quantity' => FixedPoint::format($stock, FixedPoint::STOCK_SCALE),
                    'stock_before' => FixedPoint::format(0, FixedPoint::STOCK_SCALE),
                    'stock_after' => FixedPoint::format($stock, FixedPoint::STOCK_SCALE),
                    'unit_cost' => (int) ($data['cost_price'] ?? 0),
                    'source_type' => 'opening_stock',
                    'reference' => null,
                    'notes' => 'Stok awal',
                    'occurred_at' => $now,
                    'created_by' => $request->user()?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->logger->log($request, 'product.created', 'product', $id, "Menambahkan produk {$data['name']}");

            return $id;
        }, 3);

        return response()->json(['message' => 'Produk berhasil ditambahkan.', 'id' => $id], 201);
    }

    public function updateProductPrice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'selling_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
        ]);

        $price = (int) $data['selling_price'];
        DB::transaction(function () use ($id, $price, $request): void {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($product, 404, 'Produk tidak ditemukan.');

            $before = (int) $product->selling_price;
            DB::table('products')->where('id', $id)->update([
                'selling_price' => $price,
                'updated_at' => now(),
            ]);

            $this->logger->log(
                $request,
                'product.price_updated',
                'product',
                $id,
                "Harga jual {$product->name} diperbarui",
                ['before' => $before, 'after' => $price],
            );
        }, 3);

        return response()->json(['message' => 'Harga jual berhasil diperbarui.', 'selling_price' => $price]);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'minimum_stock' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'selling_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'cost_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $id, $request): void {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($product, 404, 'Produk tidak ditemukan.');

            $unitId = (int) $data['unit_id'];
            $costPrice = array_key_exists('cost_price', $data) ? (int) $data['cost_price'] : (int) $product->cost_price;
            $now = now();
            $before = [
                'name' => $product->name,
                'category' => $product->category,
                'unit_id' => (int) $product->usage_unit_id,
                'minimum_stock' => $product->minimum_stock,
                'selling_price' => (int) $product->selling_price,
                'cost_price' => (int) $product->cost_price,
                'is_active' => (bool) $product->is_active,
            ];

            DB::table('products')->where('id', $id)->update([
                'name' => $data['name'],
                'category' => $data['category'] ?: null,
                // Form edit ini memperbaiki satuan master tunggal produk. Jumlah stok
                // tidak dikonversi otomatis agar tidak mengubah saldo tanpa persetujuan.
                'purchase_unit_id' => $unitId,
                'usage_unit_id' => $unitId,
                'purchase_to_usage_factor' => FixedPoint::format(FixedPoint::parse('1', FixedPoint::STOCK_SCALE), FixedPoint::STOCK_SCALE),
                'minimum_stock' => FixedPoint::format(
                    FixedPoint::parse($data['minimum_stock'], FixedPoint::STOCK_SCALE),
                    FixedPoint::STOCK_SCALE,
                ),
                'selling_price' => (int) $data['selling_price'],
                'cost_price' => $costPrice,
                'description' => $data['description'] ?: null,
                'is_active' => (bool) $data['is_active'],
                'updated_at' => $now,
            ]);

            if ((int) $product->usage_unit_id !== $unitId || (int) $product->purchase_unit_id !== $unitId) {
                // Resep aktif harus selalu memakai satuan yang valid untuk produk.
                // Riwayat pergerakan stok sengaja tidak diubah sebagai jejak audit.
                DB::table('treatment_product_recipes')
                    ->where('product_id', $id)
                    ->update(['unit_id' => $unitId, 'updated_at' => $now]);
            }

            $this->logger->log(
                $request,
                'product.updated',
                'product',
                $id,
                "Data produk {$product->name} diperbarui",
                ['before' => $before, 'after' => ['name' => $data['name'], 'unit_id' => $unitId, 'cost_price' => $costPrice]],
            );
        }, 3);

        return response()->json(['message' => 'Data produk berhasil diperbarui.', 'id' => $id]);
    }

    public function adjustStock(Request $request, int $id): JsonResponse
    {
        $aliases = ['masuk' => 'in', 'keluar' => 'out', 'opname' => 'adjustment'];
        $request->merge(['type' => $aliases[$request->input('type')] ?? $request->input('type')]);
        $data = $request->validate([
            'type' => ['required', Rule::in(['in', 'out', 'adjustment'])],
            'quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
            'source' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'min:3', 'max:1000', Rule::requiredIf($request->input('type') === 'out')],
        ]);

        DB::transaction(function () use ($data, $id, $request): void {
            $product = DB::table('products')->where('id', $id)->lockForUpdate()->first();
            abort_unless($product, 404, 'Produk tidak ditemukan.');
            $before = FixedPoint::parse((string) $product->current_stock, FixedPoint::STOCK_SCALE);
            $quantity = FixedPoint::parse($data['quantity'], FixedPoint::STOCK_SCALE);
            abort_if($quantity === 0, 422, 'Jumlah perubahan stok harus lebih dari nol.');

            $after = match ($data['type']) {
                'in' => $before + $quantity,
                'out' => $before - $quantity,
                'adjustment' => $quantity,
            };
            abort_if($after < 0, 422, 'Stok tidak mencukupi.');
            $movementQuantity = $data['type'] === 'adjustment' ? abs($after - $before) : $quantity;
            $now = now();

            DB::table('products')->where('id', $id)->update([
                'current_stock' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                'updated_at' => $now,
            ]);
            DB::table('stock_movements')->insert([
                'product_id' => $id,
                'unit_id' => $product->usage_unit_id,
                'type' => $data['type'],
                'quantity' => FixedPoint::format($movementQuantity, FixedPoint::STOCK_SCALE),
                'stock_before' => FixedPoint::format($before, FixedPoint::STOCK_SCALE),
                'stock_after' => FixedPoint::format($after, FixedPoint::STOCK_SCALE),
                'unit_cost' => (int) ($product->cost_price ?? 0),
                'source_type' => 'manual_adjustment',
                'reference' => null,
                'notes' => trim($data['source'].($data['notes'] ?? '' ? ' · '.$data['notes'] : '')),
                'occurred_at' => $now,
                'created_by' => $request->user()?->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->logger->log(
                $request,
                'stock.adjusted',
                'product',
                $id,
                "Stok {$product->name} diperbarui",
                ['type' => $data['type'], 'before' => FixedPoint::format($before, 4), 'after' => FixedPoint::format($after, 4)],
            );
        }, 3);

        return response()->json(['message' => 'Stok berhasil diperbarui.']);
    }

    public function storeTreatment(Request $request): JsonResponse
    {
        if (! $request->has('normal_price') && $request->has('price')) {
            $request->merge(['normal_price' => $request->input('price')]);
        }
        if (! $request->has('default_commission_percent') && $request->has('commission_percent')) {
            $request->merge(['default_commission_percent' => $request->input('commission_percent')]);
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:treatments,code'],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:treatment_categories,id'],
            'category' => ['required_without:category_id', 'string', 'max:100'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'normal_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'default_commission_percent' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $commission = FixedPoint::parse($data['default_commission_percent'], FixedPoint::PERCENT_SCALE);
        abort_if($commission > 100 * (10 ** FixedPoint::PERCENT_SCALE), 422, 'Persentase komisi tidak boleh lebih dari 100.');

        $id = DB::transaction(function () use ($data, $request): int {
            $categoryId = $data['category_id'] ?? $this->resolveOrCreateTreatmentCategory($data['category']);
            $id = DB::table('treatments')->insertGetId([
                'category_id' => $categoryId,
                'code' => $data['code'] ?? 'TRT-'.Str::upper(Str::random(8)),
                'name' => $data['name'],
                'duration_minutes' => $data['duration_minutes'],
                'normal_price' => $data['normal_price'],
                'default_commission_percent' => FixedPoint::normalizePercent($data['default_commission_percent']),
                'is_active' => true,
                'description' => $data['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->logger->log($request, 'treatment.created', 'treatment', $id, "Menambahkan treatment {$data['name']}");

            return $id;
        }, 3);

        return response()->json(['message' => 'Treatment berhasil ditambahkan.', 'id' => $id], 201);
    }

    public function updateTreatmentCommission(Request $request, int $id): JsonResponse
    {
        $treatment = DB::table('treatments')->where('id', $id)->first();
        abort_unless($treatment, 404, 'Treatment tidak ditemukan.');
        $data = $request->validate([
            'default_commission_percent' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
            'commission_profiles' => ['nullable', 'array', 'max:9'],
            'commission_profiles.*.therapist_count' => ['required', 'integer', 'min:2', 'max:10'],
            'commission_profiles.*.commission_percents' => ['required', 'array', 'min:2', 'max:10'],
            'commission_profiles.*.commission_percents.*' => ['required', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
        ]);
        $commission = FixedPoint::parse($data['default_commission_percent'], FixedPoint::PERCENT_SCALE);
        abort_if($commission > 100 * (10 ** FixedPoint::PERCENT_SCALE), 422, 'Persentase komisi tidak boleh lebih dari 100.');
        $commissionPercent = FixedPoint::format($commission, FixedPoint::PERCENT_SCALE);

        $profiles = collect($data['commission_profiles'] ?? [])->map(function (array $profile) use ($commission): array {
            $therapistCount = (int) $profile['therapist_count'];
            $percents = array_values($profile['commission_percents']);

            if (count($percents) !== $therapistCount) {
                throw ValidationException::withMessages([
                    'commission_profiles' => ["Profil {$therapistCount} therapist harus memiliki {$therapistCount} bagian komisi."],
                ]);
            }

            $scaledPercents = array_map(
                fn ($percent): int => FixedPoint::parse((string) $percent, FixedPoint::PERCENT_SCALE),
                $percents,
            );

            if (array_sum($scaledPercents) !== $commission) {
                throw ValidationException::withMessages([
                    'commission_profiles' => ["Total pembagian untuk {$therapistCount} therapist harus sama dengan komisi treatment."],
                ]);
            }

            return [
                'therapist_count' => $therapistCount,
                'commission_percents' => array_map(
                    fn (int $percent): string => FixedPoint::format($percent, FixedPoint::PERCENT_SCALE),
                    $scaledPercents,
                ),
            ];
        });

        if ($profiles->pluck('therapist_count')->unique()->count() !== $profiles->count()) {
            throw ValidationException::withMessages([
                'commission_profiles' => ['Setiap jumlah therapist hanya boleh memiliki satu profil pembagian.'],
            ]);
        }

        $defaultChanged = FixedPoint::parse((string) $treatment->default_commission_percent, FixedPoint::PERCENT_SCALE) !== $commission;

        DB::transaction(function () use ($id, $commissionPercent, $profiles, $defaultChanged): void {
            $now = now();
            DB::table('treatments')->where('id', $id)->update([
                'default_commission_percent' => $commissionPercent,
                'updated_at' => $now,
            ]);

            // Jika komisi total berubah, aturan lama tidak lagi valid. Aturan yang
            // dikirim bersama perubahan ini disimpan ulang; jumlah therapist lain
            // otomatis kembali dibagi rata dari komisi total yang baru.
            if ($defaultChanged) {
                DB::table('treatment_commission_splits')->where('treatment_id', $id)->delete();
            }

            foreach ($profiles as $profile) {
                DB::table('treatment_commission_splits')
                    ->where('treatment_id', $id)
                    ->where('therapist_count', $profile['therapist_count'])
                    ->delete();

                DB::table('treatment_commission_splits')->insert(
                    collect($profile['commission_percents'])->map(
                        fn (string $percent, int $index): array => [
                            'treatment_id' => $id,
                            'therapist_count' => $profile['therapist_count'],
                            'therapist_position' => $index + 1,
                            'commission_percent' => $percent,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    )->all(),
                );
            }
        }, 3);
        $this->logger->log(
            $request,
            'treatment.commission_updated',
            'treatment',
            $id,
            "Memperbarui komisi treatment {$treatment->name}",
            [
                'default_commission_percent' => $commissionPercent,
                'commission_profiles' => $profiles->values()->all(),
            ],
        );

        return response()->json(['message' => 'Komisi treatment berhasil diperbarui.']);
    }

    public function updateRecipe(Request $request, int $id): JsonResponse
    {
        if ($request->has('items')) {
            return $this->replaceRecipe($request, $id);
        }

        abort_unless(DB::table('treatments')->where('id', $id)->exists(), 404, 'Treatment tidak ditemukan.');
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
        ]);
        abort_if(FixedPoint::parse($data['quantity'], FixedPoint::STOCK_SCALE) === 0, 422, 'Jumlah resep harus lebih dari nol.');
        $product = DB::table('products')->where('id', $data['product_id'])->first();
        $unitId = (int) ($data['unit_id'] ?? $product->usage_unit_id);

        if (! in_array($unitId, [(int) $product->purchase_unit_id, (int) $product->usage_unit_id], true)) {
            throw ValidationException::withMessages([
                'unit_id' => ['Satuan resep harus sama dengan satuan pembelian atau satuan pemakaian produk.'],
            ]);
        }
        $identity = ['treatment_id' => $id, 'product_id' => $data['product_id']];
        $values = [
            'unit_id' => $unitId,
            'quantity' => FixedPoint::format(FixedPoint::parse($data['quantity'], 4), 4),
            'updated_at' => now(),
        ];
        DB::table('treatment_product_recipes')->upsert(
            [[...$identity, ...$values, 'created_at' => now()]],
            ['treatment_id', 'product_id'],
            ['unit_id', 'quantity', 'updated_at'],
        );
        $this->logger->log($request, 'treatment.recipe_updated', 'treatment', $id, 'Memperbarui komposisi produk treatment');

        return response()->json(['message' => 'Resep treatment berhasil diperbarui.']);
    }

    public function storeMember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $id = DB::transaction(function () use ($data, $request): int {
            if (! empty($data['email'])) {
                $emailOwner = DB::table('customers')
                    ->where('email', $data['email'])
                    ->where('phone', '!=', $data['phone'])
                    ->exists();

                if ($emailOwner) {
                    throw ValidationException::withMessages(['email' => ['Email sudah digunakan pelanggan lain.']]);
                }
            }

            $now = now();
            $updateColumns = ['name', 'is_member', 'is_active', 'updated_at'];
            if (array_key_exists('email', $data)) {
                $updateColumns[] = 'email';
            }
            DB::table('customers')->upsert([[
                'code' => 'CUS-'.Str::upper((string) Str::ulid()),
                'name' => $data['name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'is_member' => true,
                'member_since' => today(),
                'visit_count' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['phone'], $updateColumns);
            $id = (int) DB::table('customers')->where('phone', $data['phone'])->lockForUpdate()->value('id');

            $this->logger->log($request, 'membership.activated', 'customer', $id, "Mengaktifkan membership {$data['name']}");

            return $id;
        }, 3);

        return response()->json(['message' => 'Membership berhasil diaktifkan.', 'id' => $id], 201);
    }

    public function updateMember(Request $request, int $id): JsonResponse
    {
        abort_unless(
            DB::table('customers')->where('id', $id)->where('is_member', true)->exists(),
            404,
            'Member tidak ditemukan.',
        );

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('customers', 'phone')->ignore($id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('customers', 'email')->ignore($id)],
        ]);

        DB::table('customers')->where('id', $id)->update([
            ...$data,
            'updated_at' => now(),
        ]);
        $this->logger->log($request, 'membership.updated', 'customer', $id, "Memperbarui member {$data['name']}");

        return response()->json(['message' => 'Data member berhasil diperbarui.', 'id' => $id]);
    }

    public function destroyMember(Request $request, int $id): JsonResponse
    {
        $member = DB::table('customers')->where('id', $id)->where('is_member', true)->first(['id', 'name']);
        abort_unless($member, 404, 'Member tidak ditemukan.');

        // Riwayat reservasi dan transaksi tetap terhubung ke pelanggan yang sama.
        DB::table('customers')->where('id', $id)->update([
            'is_member' => false,
            'updated_at' => now(),
        ]);
        $this->logger->log($request, 'membership.deactivated', 'customer', $id, "Mencabut status member {$member->name}");

        return response()->json(['message' => 'Status membership berhasil dicabut.']);
    }

    public function storePromotion(Request $request): JsonResponse
    {
        $data = $this->validatedPromotion($request);
        $id = DB::table('promotions')->insertGetId([
            'code' => 'PRM-'.Str::upper(Str::random(8)),
            ...$data,
            'discount_type' => 'percent',
            'discount_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->logger->log($request, 'promotion.created', 'promotion', $id, "Menambahkan event {$data['name']}");

        return response()->json(['message' => 'Event membership berhasil ditambahkan.', 'id' => $id], 201);
    }

    public function updatePromotion(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('promotions')->where('id', $id)->exists(), 404, 'Event tidak ditemukan.');

        $data = $this->validatedPromotion($request);
        DB::table('promotions')->where('id', $id)->update([
            ...$data,
            'discount_type' => 'percent',
            'discount_amount' => 0,
            'updated_at' => now(),
        ]);
        $this->logger->log($request, 'promotion.updated', 'promotion', $id, "Memperbarui event {$data['name']}");

        return response()->json(['message' => 'Event membership berhasil diperbarui.', 'id' => $id]);
    }

    public function destroyPromotion(Request $request, int $id): JsonResponse
    {
        $promotion = DB::table('promotions')->where('id', $id)->first(['id', 'name']);
        abort_unless($promotion, 404, 'Event tidak ditemukan.');

        DB::table('promotions')->where('id', $id)->delete();
        $this->logger->log($request, 'promotion.deleted', 'promotion', $id, "Menghapus event {$promotion->name}");

        return response()->json(['message' => 'Event membership berhasil dihapus.']);
    }

    /** @return array{name: string, discount_percent: string, starts_at: string, ends_at: string, members_only: bool, is_active: bool, description: string|null} */
    private function validatedPromotion(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'discount_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'starts_at' => ['required', 'date_format:Y-m-d'],
            'ends_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_at'],
            'members_only' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return [
            ...$data,
            'discount_percent' => FixedPoint::normalizePercent((string) $data['discount_percent']),
            'members_only' => (bool) $data['members_only'],
            'is_active' => (bool) $data['is_active'],
        ];
    }

    public function storePayment(CheckoutRequest $request): JsonResponse
    {
        $transaction = $this->checkout->checkout($request->validated(), $request);
        $status = $transaction['idempotent_replay'] ? 200 : 201;

        return response()->json([
            'message' => $transaction['idempotent_replay']
                ? 'Pembayaran sudah pernah diproses.'
                : 'Pembayaran berhasil diproses.',
            ...$transaction,
        ], $status);
    }

    public function storeTherapistRatings(Request $request, int $transaction): JsonResponse
    {
        $data = $request->validate([
            'ratings' => ['required', 'array', 'min:1', 'max:30'],
            'ratings.*.employee_id' => ['required', 'integer', 'distinct', 'exists:employees,id'],
            'ratings.*.stars' => ['required', 'integer', 'between:1,5'],
            'ratings.*.review' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($data, $transaction, $request): void {
            $sale = DB::table('transactions')->where('id', $transaction)->lockForUpdate()->first();
            abort_unless($sale && $sale->status === 'paid', 404, 'Transaksi lunas tidak ditemukan.');

            $therapistIds = DB::table('transaction_items as item')
                ->join('reservation_item_staff as assignment', 'assignment.reservation_item_id', '=', 'item.reservation_item_id')
                ->where('item.transaction_id', $transaction)
                ->orderBy('assignment.employee_id')
                ->pluck('assignment.employee_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();
            abort_if($therapistIds->isEmpty(), 422, 'Transaksi ini tidak memiliki therapist yang dapat dinilai.');

            $submittedIds = collect($data['ratings'])->pluck('employee_id')->map(fn ($id): int => (int) $id)->sort()->values();
            abort_if(
                $submittedIds->all() !== $therapistIds->sort()->values()->all(),
                422,
                'Penilaian harus diisi untuk setiap therapist pada transaksi ini.',
            );

            $now = now();
            DB::table('therapist_ratings')->upsert(
                collect($data['ratings'])->map(fn (array $rating): array => [
                    'transaction_id' => $transaction,
                    'employee_id' => (int) $rating['employee_id'],
                    // Kolom label dipertahankan agar riwayat rilis awal tetap
                    // terbaca; nilai sebenarnya menggunakan skala 1--5 bintang.
                    'rating' => match ((int) $rating['stars']) {
                        1, 2 => 'poor',
                        3, 4 => 'good',
                        5 => 'professional',
                    },
                    'stars' => (int) $rating['stars'],
                    'review' => filled($rating['review'] ?? null) ? trim($rating['review']) : null,
                    'rated_at' => $now,
                    'rated_by' => $request->user()?->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
                ['transaction_id', 'employee_id'],
                ['rating', 'stars', 'review', 'rated_at', 'rated_by', 'updated_at'],
            );

            $this->logger->log(
                $request,
                'therapist.rated',
                'transaction',
                $transaction,
                "Mencatat penilaian therapist untuk transaksi {$sale->number}",
                ['ratings' => $data['ratings']],
            );
        }, 3);

        return response()->json(['message' => 'Penilaian therapist berhasil disimpan.']);
    }

    public function invoicePdf(int $transaction): Response
    {
        $invoice = DB::table('transactions as transaction')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->leftJoin('users as cashier', 'cashier.id', '=', 'transaction.finalized_by')
            ->where('transaction.id', $transaction)
            ->where('transaction.status', 'paid')
            ->first([
                'transaction.*',
                'customer.name as customer_name',
                'cashier.name as cashier_name',
            ]);
        abort_unless($invoice, 404, 'Nota transaksi tidak ditemukan.');

        $items = DB::table('transaction_items')
            ->where('transaction_id', $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $returnedQuantities = DB::table('sales_return_items as item')
            ->join('sales_returns as sales_return', 'sales_return.id', '=', 'item.sales_return_id')
            ->where('sales_return.transaction_id', $invoice->id)
            ->where('sales_return.status', 'posted')
            ->select('item.transaction_item_id', DB::raw('SUM(item.quantity) as quantity'))
            ->groupBy('item.transaction_item_id')
            ->pluck('quantity', 'transaction_item_id');
        $items->each(function (object $item) use ($returnedQuantities): void {
            $item->returned_quantity = (string) ($returnedQuantities->get($item->id) ?? '0.0000');
        });
        $payments = DB::table('transaction_payments as payment')
            ->join('payment_methods as method', 'method.id', '=', 'payment.payment_method_id')
            ->where('payment.transaction_id', $invoice->id)
            ->where('payment.status', 'confirmed')
            ->orderBy('payment.id')
            ->get(['payment.*', 'method.name as method_name', 'method.is_cash']);
        $therapists = DB::table('transaction_items as transaction_item')
            ->join('reservation_item_staff as assignment', 'assignment.reservation_item_id', '=', 'transaction_item.reservation_item_id')
            ->join('employees as employee', 'employee.id', '=', 'assignment.employee_id')
            ->where('transaction_item.transaction_id', $invoice->id)
            ->orderBy('employee.name')
            ->pluck('employee.name')
            ->unique()
            ->values();
        $logoPath = public_path('images/selesa-logo.png');
        $logoDataUri = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;
        $salonSettings = DB::table('sale_settings')
            ->whereIn('key', ['salon_address', 'salon_whatsapp'])
            ->pluck('value', 'key');
        $salon = [
            'address' => $salonSettings->get('salon_address') ?: 'Jl. Telaga Asmara, Tlogosari Kulon, Semarang',
            'whatsapp' => $salonSettings->get('salon_whatsapp') ?: '081128702019',
        ];

        $invoiceFilename = preg_replace('/[-_\s]+/', '', $invoice->number);

        return Pdf::loadView('pdf.invoice', compact('invoice', 'items', 'payments', 'therapists', 'logoDataUri', 'salon'))
            ->setPaper('a4')
            ->stream($invoiceFilename.'.pdf');
    }

    public function storeSalesReturn(StoreSalesReturnRequest $request, int $transaction): JsonResponse
    {
        $salesReturn = $this->salesReturns->create($transaction, $request->validated(), $request);

        return response()->json([
            'message' => $salesReturn['idempotent_replay']
                ? 'Retur sudah pernah diproses.'
                : 'Retur dan pengembalian dana berhasil diproses.',
            ...$salesReturn,
        ], $salesReturn['idempotent_replay'] ? 200 : 201);
    }

    public function salesReturnPdf(int $salesReturn): Response
    {
        $return = DB::table('sales_returns as sales_return')
            ->join('transactions as transaction', 'transaction.id', '=', 'sales_return.transaction_id')
            ->join('customers as customer', 'customer.id', '=', 'transaction.customer_id')
            ->join('payment_methods as method', 'method.id', '=', 'sales_return.refund_payment_method_id')
            ->leftJoin('users as user', 'user.id', '=', 'sales_return.created_by')
            ->where('sales_return.id', $salesReturn)
            ->where('sales_return.status', 'posted')
            ->first([
                'sales_return.*',
                'transaction.number as transaction_number',
                'transaction.transacted_at',
                'customer.name as customer_name',
                'method.name as payment_method_name',
                'user.name as created_by_name',
            ]);
        abort_unless($return, 404, 'Struk retur tidak ditemukan.');

        $items = DB::table('sales_return_items')
            ->where('sales_return_id', $return->id)
            ->orderBy('id')
            ->get();
        $logoPath = public_path('images/selesa-logo.png');
        $logoDataUri = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $reasonLines = max(1, (int) ceil(strlen((string) $return->reason) / 42));
        $receiptHeight = max(440, 370 + ($items->count() * 38) + ($reasonLines * 12));

        return Pdf::loadView('pdf.sales-return', compact('return', 'items', 'logoDataUri'))
            ->setPaper([0, 0, 164.41, $receiptHeight])
            ->stream($return->number.'.pdf');
    }

    public function storeCashEntry(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'report_group' => ['nullable', Rule::in(['operating', 'capital', 'owner_draw', 'inventory'])],
            'category' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:2000'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $id = DB::transaction(function () use ($data, $request): int {
            $now = now();
            $id = DB::table('cash_entries')->insertGetId([
                'type' => $data['type'],
                'report_group' => $data['report_group'] ?? 'operating',
                'category' => trim($data['category']),
                'description' => trim($data['description']),
                'amount' => $data['amount'],
                'entry_date' => $data['entry_date'],
                'status' => 'posted',
                'created_by' => $request->user()?->id,
                'approved_by' => $request->user()?->id,
                'approved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $typeLabel = $data['type'] === 'income' ? 'pemasukan' : 'pengeluaran';
            $this->logger->log(
                $request,
                'finance.cash_entry_created',
                'cash_entry',
                $id,
                "Mencatat {$typeLabel}: {$data['category']}",
                ['type' => $data['type'], 'report_group' => $data['report_group'] ?? 'operating', 'amount' => $data['amount'], 'entry_date' => $data['entry_date']],
            );

            return $id;
        }, 3);

        return response()->json([
            'message' => 'Arus kas berhasil dicatat.',
            'id' => $id,
        ], 201);
    }

    public function storePayroll(Request $request): JsonResponse
    {
        $data = $this->validatedPayrollInputs($request, true);

        $id = DB::transaction(function () use ($data, $request): int {
            $employee = DB::table('employees')
                ->where('id', $data['employee_id'])
                ->where('active', true)
                ->lockForUpdate()
                ->first();
            abort_unless($employee, 422, 'Karyawan tidak aktif atau tidak ditemukan.');

            if (DB::table('payrolls')
                ->where('employee_id', $employee->id)
                ->where('period', $data['period'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Penggajian karyawan ini untuk periode tersebut sudah dibuat.'],
                ]);
            }

            $amounts = $this->resolvedPayrollAmounts(
                $data,
                null,
                $this->payrollCommission((int) $employee->id, $data['period']),
            );
            $now = now();
            $id = DB::table('payrolls')->insertGetId([
                'employee_id' => $employee->id,
                'period' => $data['period'],
                'employee_name' => $employee->name,
                'position' => $employee->position,
                ...$amounts,
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->logger->log(
                $request,
                'payroll.created',
                'payroll',
                $id,
                "Membuat data remunerasi {$employee->name} periode {$data['period']}",
                ['employee_id' => (int) $employee->id, 'period' => $data['period']],
            );

            return $id;
        }, 3);

        return response()->json([
            'message' => 'Data remunerasi berhasil ditambahkan.',
            'id' => $id,
        ], 201);
    }

    public function updatePayroll(Request $request, int $id): JsonResponse
    {
        $data = $this->validatedPayrollInputs($request, false);

        DB::transaction(function () use ($id, $data, $request): void {
            $payroll = DB::table('payrolls')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payroll, 404, 'Data penggajian tidak ditemukan.');
            abort_if($payroll->status !== 'draft', 422, 'Penggajian yang sudah difinalisasi tidak dapat diubah.');

            $amounts = $this->resolvedPayrollAmounts(
                $data,
                $payroll,
                $this->payrollCommission((int) $payroll->employee_id, $payroll->period),
            );
            DB::table('payrolls')->where('id', $id)->update([
                ...$amounts,
                'updated_at' => now(),
            ]);
            $this->logger->log($request, 'payroll.updated', 'payroll', $id, 'Memperbarui komponen remunerasi pegawai');
        }, 3);

        return response()->json(['message' => 'Data remunerasi berhasil diperbarui.']);
    }

    public function payrollSlipPdf(Request $request, int $id): Response
    {
        $payroll = DB::table('payrolls as payroll')
            ->join('employees as employee', 'employee.id', '=', 'payroll.employee_id')
            ->where('payroll.id', $id)
            ->first(['payroll.*', 'employee.name as current_employee_name', 'employee.position as current_position']);
        abort_unless($payroll, 404, 'Data penggajian tidak ditemukan.');
        $start = CarbonImmutable::createFromFormat('!Y-m', $payroll->period)->startOfMonth();
        $end = $start->endOfMonth();
        $treatmentCount = (float) DB::table('reservation_item_staff as assignment')
            ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
            ->join('reservations as reservation', 'reservation.id', '=', 'item.reservation_id')
            ->join('transactions as transaction', 'transaction.reservation_id', '=', 'reservation.id')
            ->join('transaction_items as transactionItem', function ($join): void {
                $join->on('transactionItem.transaction_id', '=', 'transaction.id')
                    ->on('transactionItem.reservation_item_id', '=', 'item.id');
            })
            ->where('assignment.employee_id', $payroll->employee_id)
            ->where('transaction.status', 'paid')
            ->whereBetween('transaction.transacted_at', [$start->startOfDay(), $end->endOfDay()])
            ->sum('transactionItem.quantity');
        $amount = fn (string $field): int => (int) ($payroll->{$field} ?? 0);
        $decimal = fn (string $field): float => (float) ($payroll->{$field} ?? 0);
        $totalBonus = $amount('bonus') + $amount('target_bonus') + $amount('service_bonus') + $amount('attendance_bonus');
        $totalAllowance = $amount('meal_allowance') + $amount('attendance_allowance') + $amount('other_allowance');
        $grossIncome = $amount('base_salary') + $amount('commission') + $amount('overtime') + $totalBonus + $totalAllowance + $amount('tip_deposit');
        $totalDeduction = $amount('absence_deduction') + $amount('late_deduction') + $amount('cash_advance') + $amount('other_deduction');
        $employee = [
            'employee_name' => $payroll->employee_name ?: $payroll->current_employee_name,
            'position' => $payroll->position ?: $payroll->current_position,
            'paid_work_days' => $decimal('paid_work_days'),
            'absence_days' => $decimal('absence_days'),
            'late_minutes' => $amount('late_duration_minutes'),
            'treatment_count' => $treatmentCount,
            'base_salary' => $amount('base_salary'),
            'commission' => $amount('commission'),
            'overtime_days' => $decimal('overtime_days'),
            'overtime' => $amount('overtime'),
            'meal_allowance' => $amount('meal_allowance'),
            'total_allowance' => $totalAllowance,
            'total_bonus' => $totalBonus,
            'tip_deposit' => $amount('tip_deposit'),
            'absence_deduction' => $amount('absence_deduction'),
            'late_deduction' => $amount('late_deduction'),
            'cash_advance' => $amount('cash_advance'),
            'other_deduction' => $amount('other_deduction'),
            'gross_income' => $grossIncome,
            'total_deduction' => $totalDeduction,
            'net_salary' => $grossIncome - $totalDeduction,
            'notes' => $payroll->notes,
        ];

        $settings = DB::table('sale_settings')
            ->whereIn('key', ['salon_address', 'salon_whatsapp'])
            ->pluck('value', 'key');
        $salon = [
            'address' => $settings->get('salon_address') ?: 'Jl. Telaga Asmara, Tlogosari Kulon, Semarang',
            'whatsapp' => $settings->get('salon_whatsapp') ?: '081128702019',
        ];
        $logoPath = public_path('images/selesa-logo.png');
        $logoDataUri = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;
        $periodLabel = $start->translatedFormat('F Y');
        $printedBy = $request->user()?->name ?: 'Owner Selesa';

        return Pdf::loadView('pdf.payroll-slip', compact('employee', 'salon', 'logoDataUri', 'periodLabel', 'printedBy'))
            ->setPaper('a4')
            ->stream('slip-gaji-'.Str::slug($employee['employee_name']).'-'.$payroll->period.'.pdf');
    }

    private function legacyStorePayroll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'period' => ['required', 'date_format:Y-m'],
            'base_salary' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'bonus' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'overtime' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_deduction' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'other_deduction' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        $id = DB::transaction(function () use ($data, $request): int {
            $employee = DB::table('employees')
                ->where('id', $data['employee_id'])
                ->where('active', true)
                ->lockForUpdate()
                ->first();
            abort_unless($employee, 422, 'Karyawan tidak aktif atau tidak ditemukan.');

            if (DB::table('payrolls')
                ->where('employee_id', $employee->id)
                ->where('period', $data['period'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Penggajian karyawan ini untuk periode tersebut sudah dibuat.'],
                ]);
            }

            $bonus = (int) ($data['bonus'] ?? 0);
            $overtime = (int) ($data['overtime'] ?? 0);
            $lateDeduction = (int) ($data['late_deduction'] ?? 0);
            $otherDeduction = (int) ($data['other_deduction'] ?? 0);
            $commission = $this->payrollCommission((int) $employee->id, $data['period']);
            $gross = (int) $data['base_salary'] + $bonus + $overtime + $commission;
            $deductions = $lateDeduction + $otherDeduction;
            abort_if($deductions > $gross, 422, 'Total potongan tidak boleh melebihi pendapatan.');

            $now = now();
            $id = DB::table('payrolls')->insertGetId([
                'employee_id' => $employee->id,
                'period' => $data['period'],
                'employee_name' => $employee->name,
                'position' => $employee->position,
                'base_salary' => $data['base_salary'],
                'bonus' => $bonus,
                'overtime' => $overtime,
                'commission' => $commission,
                'late_deduction' => $lateDeduction,
                'other_deduction' => $otherDeduction,
                'net_salary' => $gross - $deductions,
                'late_duration_minutes' => (int) ($data['late_duration_minutes'] ?? 0),
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->logger->log(
                $request,
                'payroll.created',
                'payroll',
                $id,
                "Membuat penggajian {$employee->name} periode {$data['period']}",
                ['employee_id' => (int) $employee->id, 'period' => $data['period']],
            );

            return $id;
        }, 3);

        return response()->json([
            'message' => 'Data penggajian berhasil ditambahkan.',
            'id' => $id,
        ], 201);
    }

    private function legacyUpdatePayroll(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'base_salary' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'bonus' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'overtime' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_deduction' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'other_deduction' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'late_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        DB::transaction(function () use ($id, $data, $request): void {
            $payroll = DB::table('payrolls')->where('id', $id)->lockForUpdate()->first();
            abort_unless($payroll, 404, 'Data penggajian tidak ditemukan.');
            abort_if($payroll->status !== 'draft', 422, 'Penggajian yang sudah difinalisasi tidak dapat diubah.');

            $overtime = (int) ($data['overtime'] ?? $payroll->overtime);
            // Komisi adalah hasil transaksi layanan, bukan nilai yang diinput manual.
            $commission = $this->payrollCommission((int) $payroll->employee_id, $payroll->period);
            $otherDeduction = (int) ($data['other_deduction'] ?? $payroll->other_deduction);
            $gross = (int) $data['base_salary'] + (int) $data['bonus'] + $overtime + $commission;
            $deductions = (int) $data['late_deduction'] + $otherDeduction;
            abort_if($deductions > $gross, 422, 'Total potongan tidak boleh melebihi pendapatan.');

            DB::table('payrolls')->where('id', $id)->update([
                'base_salary' => $data['base_salary'],
                'bonus' => $data['bonus'],
                'overtime' => $overtime,
                'commission' => $commission,
                'late_deduction' => $data['late_deduction'],
                'other_deduction' => $otherDeduction,
                'net_salary' => $gross - $deductions,
                'late_duration_minutes' => $data['late_duration_minutes'] ?? $payroll->late_duration_minutes,
                'updated_at' => now(),
            ]);
            $this->logger->log($request, 'payroll.updated', 'payroll', $id, 'Memperbarui komponen gaji pegawai');
        }, 3);

        return response()->json(['message' => 'Data gaji berhasil diperbarui.']);
    }

    /** @return array<string, mixed> */
    private function validatedPayrollInputs(Request $request, bool $creating): array
    {
        $currencyFields = [
            'base_salary',
            'daily_rate',
            'bonus',
            'target_bonus',
            'service_bonus',
            'attendance_bonus',
            'overtime',
            'meal_allowance',
            'attendance_allowance',
            'other_allowance',
            'tip_deposit',
            'absence_deduction',
            'late_rate_per_minute',
            'late_deduction',
            'cash_advance',
            'other_deduction',
        ];
        $rules = [
            'paid_work_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'overtime_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'absence_days' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'late_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
        foreach ($currencyFields as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:0', 'max:999999999999'];
        }
        if ($creating) {
            $rules = [
                'employee_id' => ['required', 'integer', 'exists:employees,id'],
                'period' => ['required', 'date_format:Y-m'],
                ...$rules,
            ];
        }

        return $request->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, int|float|string|null>
     */
    private function resolvedPayrollAmounts(array $input, ?object $existing, int $commission): array
    {
        $number = function (string $field) use ($input, $existing): int {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                return (int) $input[$field];
            }

            return (int) ($existing->{$field} ?? 0);
        };
        $decimal = function (string $field) use ($input, $existing): float {
            if (array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '') {
                return round((float) $input[$field], 2);
            }

            return round((float) ($existing->{$field} ?? 0), 2);
        };
        $paidWorkDays = $decimal('paid_work_days');
        $dailyRate = $number('daily_rate');
        $enteredBaseSalary = $number('base_salary');
        if ($paidWorkDays > 0 && $dailyRate === 0 && $enteredBaseSalary === 0) {
            throw ValidationException::withMessages([
                'base_salary' => ['Isi gaji pokok agar GP per hari dapat dihitung.'],
            ]);
        }

        $baseSalary = $enteredBaseSalary > 0
            ? $enteredBaseSalary
            : (int) round($paidWorkDays * $dailyRate);
        if ($dailyRate === 0 && $paidWorkDays > 0 && $baseSalary > 0) {
            $dailyRate = (int) round($baseSalary / $paidWorkDays);
        }
        $absenceDays = $decimal('absence_days');
        $absenceDeduction = $dailyRate > 0 && $absenceDays > 0
            ? (int) round($absenceDays * $dailyRate)
            : $number('absence_deduction');
        $lateMinutes = $number('late_duration_minutes');
        $lateRate = $number('late_rate_per_minute');
        $lateDeduction = $lateMinutes > 0 && $lateRate > 0
            ? $lateMinutes * $lateRate
            : $number('late_deduction');
        $bonus = $number('bonus');
        $targetBonus = $number('target_bonus');
        $serviceBonus = $number('service_bonus');
        $attendanceBonus = $number('attendance_bonus');
        $overtime = $number('overtime');
        $mealAllowance = $number('meal_allowance');
        $attendanceAllowance = $number('attendance_allowance');
        $otherAllowance = $number('other_allowance');
        $tipDeposit = $number('tip_deposit');
        $cashAdvance = $number('cash_advance');
        $otherDeduction = $number('other_deduction');
        $gross = $baseSalary + $commission + $bonus + $targetBonus + $serviceBonus + $attendanceBonus
            + $overtime + $mealAllowance + $attendanceAllowance + $otherAllowance + $tipDeposit;
        $deductions = $absenceDeduction + $lateDeduction + $cashAdvance + $otherDeduction;
        if ($deductions > $gross) {
            throw ValidationException::withMessages([
                'other_deduction' => ['Total potongan tidak boleh melebihi pendapatan kotor.'],
            ]);
        }

        return [
            'base_salary' => $baseSalary,
            'paid_work_days' => $paidWorkDays,
            'daily_rate' => $dailyRate,
            'bonus' => $bonus,
            'target_bonus' => $targetBonus,
            'service_bonus' => $serviceBonus,
            'attendance_bonus' => $attendanceBonus,
            'overtime' => $overtime,
            'overtime_days' => $decimal('overtime_days'),
            'meal_allowance' => $mealAllowance,
            'attendance_allowance' => $attendanceAllowance,
            'other_allowance' => $otherAllowance,
            'tip_deposit' => $tipDeposit,
            'commission' => $commission,
            'absence_days' => $absenceDays,
            'absence_deduction' => $absenceDeduction,
            'late_duration_minutes' => $lateMinutes,
            'late_rate_per_minute' => $lateRate,
            'late_deduction' => $lateDeduction,
            'cash_advance' => $cashAdvance,
            'other_deduction' => $otherDeduction,
            'net_salary' => $gross - $deductions,
            'notes' => array_key_exists('notes', $input) ? ($input['notes'] ?: null) : ($existing->notes ?? null),
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, int>  $currencyColumns
     */
    private function spreadsheetResponse(
        string $filename,
        string $title,
        string $sheetName,
        array $headers,
        array $rows,
        array $currencyColumns = [],
    ): StreamedResponse {
        return response()->streamDownload(function () use ($sheetName, $title, $headers, $rows, $currencyColumns): void {
            echo $this->spreadsheets->make($sheetName, $title, $headers, $rows, $currencyColumns);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function remunerationRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $timezone = config('app.timezone');
        $today = CarbonImmutable::today($timezone);
        $from = isset($data['from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $data['from'], $timezone)
            : $today->startOfMonth();
        $to = isset($data['to'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $data['to'], $timezone)
            : $today;

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'to' => ['Tanggal akhir tidak boleh sebelum tanggal awal.'],
            ]);
        }

        return [$from, $to];
    }

    private function stockSourceLabel(?string $source): string
    {
        return match ($source) {
            'opening_stock' => 'Stok awal',
            'manual_adjustment' => 'Penyesuaian manual',
            'transaction' => 'Pemakaian resep treatment',
            'transaction_sale' => 'Penjualan produk',
            default => $source ?: '-',
        };
    }

    private function payrollCommission(int $employeeId, string $period): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period)->startOfMonth();
        $end = $start->addMonth();

        return (int) DB::table('reservation_item_staff as assignment')
            ->join('reservation_items as item', 'item.id', '=', 'assignment.reservation_item_id')
            ->join('transactions as transaction', 'transaction.reservation_id', '=', 'item.reservation_id')
            ->join('transaction_items as transaction_item', function ($join): void {
                $join->on('transaction_item.transaction_id', '=', 'transaction.id')
                    ->on('transaction_item.reservation_item_id', '=', 'item.id');
            })
            ->where('assignment.employee_id', $employeeId)
            ->where('transaction.status', 'paid')
            ->where('transaction.transacted_at', '>=', $start)
            ->where('transaction.transacted_at', '<', $end)
            ->sum('assignment.commission_amount');
    }

    private function replaceRecipe(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('treatments')->where('id', $id)->exists(), 404, 'Treatment tidak ditemukan.');

        $data = $request->validate([
            'items' => ['present', 'array', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
        ]);
        $items = collect($data['items']);
        $productIds = $items->pluck('product_id')->map(fn ($productId) => (int) $productId);

        if ($productIds->unique()->count() !== $productIds->count()) {
            throw ValidationException::withMessages([
                'items' => ['Setiap produk hanya boleh dipilih satu kali dalam resep.'],
            ]);
        }

        $now = now();

        DB::transaction(function () use ($id, $items, $productIds, $now): void {
            DB::table('treatments')->where('id', $id)->lockForUpdate()->firstOrFail();
            $products = DB::table('products')
                ->whereIn('id', $productIds)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get(['id', 'usage_unit_id'])
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => ['Satu atau lebih produk tidak aktif atau tidak ditemukan.'],
                ]);
            }

            $rows = $items->map(function (array $item) use ($id, $products, $now): array {
                $quantity = FixedPoint::parse($item['quantity'], FixedPoint::STOCK_SCALE);
                abort_if($quantity === 0, 422, 'Jumlah pemakaian resep harus lebih dari nol.');
                $product = $products->get((int) $item['product_id']);

                return [
                    'treatment_id' => $id,
                    'product_id' => (int) $item['product_id'],
                    'unit_id' => $product->usage_unit_id,
                    'quantity' => FixedPoint::format($quantity, FixedPoint::STOCK_SCALE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->all();

            DB::table('treatment_product_recipes')->where('treatment_id', $id)->delete();

            if ($rows !== []) {
                DB::table('treatment_product_recipes')->insert($rows);
            }
        }, 3);

        $this->logger->log(
            $request,
            'treatment.recipe_updated',
            'treatment',
            $id,
            'Memperbarui komposisi produk treatment',
            ['product_count' => $items->count()],
        );

        return response()->json(['message' => 'Resep treatment berhasil diperbarui.']);
    }

    private function resolveOrCreateTreatmentCategory(string $name): int
    {
        $now = now();
        $existing = DB::table('treatment_categories')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');

        if ($existing) {
            DB::table('treatment_categories')->where('id', $existing)->update(['is_active' => true, 'updated_at' => $now]);

            return (int) $existing;
        }

        DB::table('treatment_categories')->upsert([[
            'code' => 'CAT-'.Str::upper(Str::random(8)),
            'name' => $name,
            'sort_order' => 0,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['name'], ['is_active', 'updated_at']);

        return (int) DB::table('treatment_categories')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->value('id');
    }
}
