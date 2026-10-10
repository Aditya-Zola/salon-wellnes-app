<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyCashAndRemunerationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_one_month_reconciles_deposits_sales_returns_cash_and_custom_commissions(): void
    {
        $admin = User::where('email', 'admin@gmail.com')->firstOrFail();
        $cashier = User::where('email', 'kasir@gmail.com')->firstOrFail();
        $facial = DB::table('treatments')->where('code', 'TRT-FACIAL-BARRIER')->firstOrFail();
        $nail = DB::table('treatments')->where('code', 'TRT-NAIL-GEL-HAND')->firstOrFail();
        $product = DB::table('products')->where('code', 'PRD-HERBAL-DRINK')->firstOrFail();
        $dita = DB::table('employees')->where('code', 'EMP-DITA')->firstOrFail();
        $rani = DB::table('employees')->where('code', 'EMP-RANI')->firstOrFail();
        $sari = DB::table('employees')->where('code', 'EMP-SARI')->firstOrFail();
        $cash = DB::table('payment_methods')->where('code', 'CASH')->firstOrFail();
        $qris = DB::table('payment_methods')->where('code', 'QRIS-001')->firstOrFail();

        Carbon::setTestNow('2033-06-03 10:00:00');
        $firstReservation = $this->actingAs($admin)->postJson('/operasional/reservasi', [
            'customer_type' => 'guest',
            'name' => 'Pelanggan Bulanan 1',
            'phone' => '081299901001',
            'date' => '2033-06-03',
            'source' => 'walk_in',
            'items' => [[
                'treatment_id' => $facial->id,
                'start_time' => '10:00',
                'staff' => [
                    ['employee_id' => $dita->id, 'role' => 'primary', 'commission_percent' => 2],
                    ['employee_id' => $rani->id, 'role' => 'assistant', 'commission_percent' => 1],
                ],
            ]],
            'deposit' => ['payment_method_id' => $qris->id, 'amount' => 30000],
        ])->assertCreated();
        $firstReservationId = (int) $firstReservation->json('id');
        $this->finishReservation($firstReservationId);
        $firstTotal = (int) $facial->normal_price + 2 * (int) $product->selling_price;
        $firstTransactionId = (int) $this->actingAs($cashier)->postJson('/operasional/pembayaran', [
            'reservation_id' => $firstReservationId,
            'product_items' => [['product_id' => $product->id, 'quantity' => '2.0000']],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => $firstTotal - 30000]],
        ])->assertCreated()->assertJsonPath('total', $firstTotal)->json('id');

        Carbon::setTestNow('2033-06-10 11:00:00');
        $secondReservationId = $this->reserveOneTreatment($admin, $nail->id, $sari->id, '2033-06-10', '081299901002');
        $this->finishReservation($secondReservationId);
        $secondTotal = (int) $nail->normal_price;
        $this->actingAs($cashier)->postJson('/operasional/pembayaran', [
            'reservation_id' => $secondReservationId,
            'payments' => [['payment_method_id' => $cash->id, 'amount' => $secondTotal]],
        ])->assertCreated()->assertJsonPath('total', $secondTotal);

        Carbon::setTestNow('2033-06-22 12:00:00');
        $thirdReservationId = $this->reserveOneTreatment($admin, $facial->id, $dita->id, '2033-06-22', '081299901003', 1.5);
        $this->finishReservation($thirdReservationId);
        $thirdTotal = (int) $facial->normal_price;
        $thirdTransactionId = (int) $this->actingAs($cashier)->postJson('/operasional/pembayaran', [
            'reservation_id' => $thirdReservationId,
            'payments' => [['payment_method_id' => $cash->id, 'amount' => $thirdTotal]],
        ])->assertCreated()->assertJsonPath('total', $thirdTotal)->json('id');

        $productItemId = (int) DB::table('transaction_items')->where('transaction_id', $firstTransactionId)->where('item_type', 'product')->value('id');
        $treatmentItemId = (int) DB::table('transaction_items')->where('transaction_id', $thirdTransactionId)->where('item_type', 'treatment')->value('id');
        $productRefund = (int) $product->selling_price;
        $treatmentRefund = 20000;
        $this->actingAs($admin)->postJson("/operasional/penjualan/{$firstTransactionId}/retur", [
            'items' => [['transaction_item_id' => $productItemId, 'quantity' => '1.0000', 'restock' => true]],
            'payment_method_id' => $cash->id,
            'reason' => 'Satu produk dikembalikan pelanggan.',
        ])->assertCreated()->assertJsonPath('total_amount', $productRefund);
        $this->actingAs($admin)->postJson("/operasional/penjualan/{$thirdTransactionId}/retur", [
            'treatment_items' => [['transaction_item_id' => $treatmentItemId, 'refund_amount' => $treatmentRefund]],
            'payment_method_id' => $cash->id,
            'reason' => 'Pengembalian sebagian biaya treatment.',
        ])->assertCreated()->assertJsonPath('total_amount', $treatmentRefund);

        $this->actingAs($admin)->postJson('/operasional/keuangan/arus-kas', [
            'type' => 'expense', 'category' => 'Operasional', 'description' => 'Biaya operasional bulanan',
            'amount' => 25000, 'entry_date' => '2033-06-22',
        ])->assertCreated();
        $this->actingAs($admin)->postJson('/operasional/keuangan/arus-kas', [
            'type' => 'income', 'report_group' => 'capital', 'category' => 'Modal',
            'description' => 'Tambahan modal pemilik', 'amount' => 100000, 'entry_date' => '2033-06-22',
        ])->assertCreated();

        Carbon::setTestNow('2033-06-30 18:00:00');
        $finance = $this->actingAs($admin)->getJson('/operasional/keuangan/laporan?from=2033-06-01&to=2033-06-30&as_of=2033-06-30')->assertOk()->json();
        $grossSales = $firstTotal + $secondTotal + $thirdTotal;
        $refunds = $productRefund + $treatmentRefund;
        $this->assertSame($grossSales, $finance['profit_loss']['sales_revenue']);
        $this->assertSame($refunds, $finance['profit_loss']['sales_returns']);
        $this->assertSame($grossSales - $refunds, $finance['profit_loss']['revenue_total']);
        $this->assertSame(25000, $finance['profit_loss']['manual_expense']);
        $this->assertSame(0, $finance['profit_loss']['manual_income']);
        $this->assertSame(100000, $finance['cash_flow']['income']);
        $this->assertSame(25000, $finance['cash_flow']['expense']);
        $this->assertSame(2, $finance['cash_flow']['entry_count']);

        $flows = collect($finance['cash_flow']['payment_flows'])->keyBy('id');
        $this->assertSame($grossSales - 30000 - $refunds, $flows[$cash->id]['net']);
        $this->assertSame(30000, $flows[$qris->id]['net']);
        $this->assertSame($grossSales - $refunds, $flows[$cash->id]['net'] + $flows[$qris->id]['net']);

        $remuneration = $this->actingAs($admin)->getJson('/operasional/penggajian/rekap?from=2033-06-01&to=2033-06-30')->assertOk()->json();
        $employees = collect($remuneration['employees'])->keyBy('employee_id');
        $this->assertSame(3325, $employees[$dita->id]['commission']);
        $this->assertSame(950, $employees[$rani->id]['commission']);
        $this->assertSame(4000, $employees[$sari->id]['commission']);
        $this->assertCount(3, DB::table('transactions')->where('status', 'paid')->get());
        $this->assertCount(2, DB::table('sales_returns')->where('status', 'posted')->get());
    }

    private function reserveOneTreatment(User $admin, int $treatmentId, int $employeeId, string $date, string $phone, ?float $commission = null): int
    {
        $staff = ['employee_id' => $employeeId, 'role' => 'primary'];
        if ($commission !== null) {
            $staff['commission_percent'] = $commission;
        }

        return (int) $this->actingAs($admin)->postJson('/operasional/reservasi', [
            'customer_type' => 'guest', 'name' => 'Pelanggan '.$phone, 'phone' => $phone,
            'date' => $date, 'source' => 'walk_in',
            'items' => [['treatment_id' => $treatmentId, 'start_time' => '10:00', 'staff' => [$staff]]],
        ])->assertCreated()->json('id');
    }

    private function finishReservation(int $reservationId): void
    {
        DB::table('reservation_items')->where('reservation_id', $reservationId)->update([
            'work_status' => 'finished', 'finished_at' => now(),
        ]);
    }
}
