<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\Payroll;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Reservation;
use App\Models\ReservationItem;
use App\Models\ReservationItemStaff;
use App\Models\Treatment;
use App\Models\TreatmentCategory;
use App\Models\TreatmentProductRecipe;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalonOperationSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $units = collect([
                ['code' => 'ML', 'name' => 'Mililiter', 'decimal_places' => 2],
                ['code' => 'GR', 'name' => 'Gram', 'decimal_places' => 2],
                ['code' => 'PCS', 'name' => 'Pcs', 'decimal_places' => 0],
                ['code' => 'SACHET', 'name' => 'Sachet', 'decimal_places' => 0],
            ])->mapWithKeys(function (array $attributes) {
                $unit = Unit::firstOrCreate(
                    ['code' => $attributes['code']],
                    [...$attributes, 'is_active' => true],
                );

                return [$attributes['code'] => $unit];
            });

            $categories = collect([
                ['code' => 'FACIAL', 'name' => 'Facial Ritual', 'sort_order' => 10],
                ['code' => 'HEALTHY_HAIR', 'name' => 'Healthy Hair', 'sort_order' => 20],
                ['code' => 'HAIR_RITUAL', 'name' => 'Hair Ritual', 'sort_order' => 30],
                ['code' => 'BUNDLE', 'name' => 'Special Bundle', 'sort_order' => 40],
                ['code' => 'NAIL', 'name' => 'Luxe Nail', 'sort_order' => 50],
                ['code' => 'WELLNESS', 'name' => 'Wellness & Spa', 'sort_order' => 60],
            ])->mapWithKeys(function (array $attributes) {
                $category = TreatmentCategory::firstOrCreate(
                    ['code' => $attributes['code']],
                    [...$attributes, 'is_active' => true],
                );

                return [$attributes['code'] => $category];
            });

            $employees = collect([
                ['code' => 'EMP-DITA', 'name' => 'Dita', 'position' => 'Therapist', 'specialty' => 'Hair therapist', 'is_service_provider' => true],
                ['code' => 'EMP-RANI', 'name' => 'Rani', 'position' => 'Therapist', 'specialty' => 'Beauty therapist', 'is_service_provider' => true],
                ['code' => 'EMP-MAYA', 'name' => 'Maya', 'position' => 'Therapist', 'specialty' => 'Hair therapist', 'is_service_provider' => true],
                ['code' => 'EMP-SARI', 'name' => 'Sari', 'position' => 'Nail Artist', 'specialty' => 'Nail artist', 'is_service_provider' => true],
                ['code' => 'EMP-VINA', 'name' => 'Vina', 'position' => 'Marketing', 'specialty' => null, 'is_service_provider' => false],
            ])->mapWithKeys(function (array $attributes) {
                $employee = Employee::firstOrCreate(
                    ['code' => $attributes['code']],
                    [...$attributes, 'active' => true],
                );

                return [$attributes['code'] => $employee];
            });

            $treatments = collect([
                ['code' => 'TRT-FACIAL-BARRIER', 'category' => 'FACIAL', 'name' => 'Skin Barrier Facial', 'duration_minutes' => 60, 'normal_price' => 95000, 'default_commission_percent' => 5],
                ['code' => 'TRT-HAIR-LOREAL', 'category' => 'HEALTHY_HAIR', 'name' => "L'Oreal Hair Spa", 'duration_minutes' => 90, 'normal_price' => 185000, 'default_commission_percent' => 5],
                ['code' => 'TRT-CREAMBATH-MKRZ', 'category' => 'HAIR_RITUAL', 'name' => 'Makarizo Creambath', 'duration_minutes' => 60, 'normal_price' => 125000, 'default_commission_percent' => 5],
                ['code' => 'TRT-LUNARA-PACKAGE', 'category' => 'BUNDLE', 'name' => 'Lunara Package', 'duration_minutes' => 120, 'normal_price' => 205000, 'default_commission_percent' => 5],
                ['code' => 'TRT-NAIL-GEL-HAND', 'category' => 'NAIL', 'name' => 'Nail Gel Polish - Hand', 'duration_minutes' => 60, 'normal_price' => 80000, 'default_commission_percent' => 5],
                ['code' => 'TRT-WELLNESS-SPA', 'category' => 'WELLNESS', 'name' => 'Wellness Spa', 'duration_minutes' => 60, 'normal_price' => 145000, 'default_commission_percent' => 7],
            ])->mapWithKeys(function (array $attributes) use ($categories) {
                $categoryCode = $attributes['category'];
                unset($attributes['category']);

                $treatment = Treatment::firstOrCreate(
                    ['code' => $attributes['code']],
                    [
                        ...$attributes,
                        'category_id' => $categories[$categoryCode]->id,
                        'is_active' => true,
                    ],
                );

                return [$attributes['code'] => $treatment];
            });

            $products = collect([
                ['code' => 'PRD-LOREAL-SPA', 'name' => "Hair Spa L'Oreal", 'category' => 'Hair', 'purchase_unit' => 'GR', 'usage_unit' => 'GR', 'factor' => 1, 'stock' => 400, 'minimum' => 150, 'price' => 0],
                ['code' => 'PRD-ERHA-SERUM', 'name' => 'ERHA Hair Growth Serum', 'category' => 'Hair', 'purchase_unit' => 'ML', 'usage_unit' => 'ML', 'factor' => 1, 'stock' => 120, 'minimum' => 75, 'price' => 0],
                ['code' => 'PRD-BARRIER-MASK', 'name' => 'Skin Barrier Mask', 'category' => 'Facial', 'purchase_unit' => 'PCS', 'usage_unit' => 'PCS', 'factor' => 1, 'stock' => 18, 'minimum' => 10, 'price' => 0],
                ['code' => 'PRD-HERBAL-DRINK', 'name' => 'Herbal Drink', 'category' => 'Konsumsi', 'purchase_unit' => 'SACHET', 'usage_unit' => 'SACHET', 'factor' => 1, 'stock' => 9, 'minimum' => 12, 'price' => 10000],
            ])->mapWithKeys(function (array $attributes) use ($units) {
                $product = Product::firstOrCreate(
                    ['code' => $attributes['code']],
                    [
                        'name' => $attributes['name'],
                        'category' => $attributes['category'],
                        'purchase_unit_id' => $units[$attributes['purchase_unit']]->id,
                        'usage_unit_id' => $units[$attributes['usage_unit']]->id,
                        'purchase_to_usage_factor' => $attributes['factor'],
                        'current_stock' => $attributes['stock'],
                        'minimum_stock' => $attributes['minimum'],
                        'selling_price' => $attributes['price'],
                        'is_active' => true,
                    ],
                );

                return [$attributes['code'] => $product];
            });

            foreach ([
                ['treatment' => 'TRT-FACIAL-BARRIER', 'product' => 'PRD-BARRIER-MASK', 'quantity' => 1],
                ['treatment' => 'TRT-HAIR-LOREAL', 'product' => 'PRD-LOREAL-SPA', 'quantity' => 20],
                ['treatment' => 'TRT-WELLNESS-SPA', 'product' => 'PRD-HERBAL-DRINK', 'quantity' => 1],
            ] as $recipe) {
                $product = $products[$recipe['product']];
                TreatmentProductRecipe::firstOrCreate(
                    [
                        'treatment_id' => $treatments[$recipe['treatment']]->id,
                        'product_id' => $product->id,
                    ],
                    [
                        'unit_id' => $product->usage_unit_id,
                        'quantity' => $recipe['quantity'],
                    ],
                );
            }

            $customers = collect([
                ['code' => 'CUST-0001', 'name' => 'Nadia Prameswari', 'phone' => '081234567801', 'is_member' => true, 'visit_count' => 8],
                ['code' => 'CUST-0002', 'name' => 'Alya Putri', 'phone' => '081377881204', 'is_member' => true, 'visit_count' => 5],
                ['code' => 'CUST-0003', 'name' => 'Siska Amanda', 'phone' => '085722018890', 'is_member' => true, 'visit_count' => 3],
                ['code' => 'CUST-0004', 'name' => 'Rina Ayu', 'phone' => '081211884420', 'is_member' => false, 'visit_count' => 0],
            ])->mapWithKeys(function (array $attributes) {
                $customer = Customer::firstOrCreate(
                    ['code' => $attributes['code']],
                    [
                        ...$attributes,
                        'member_since' => $attributes['is_member'] ? today() : null,
                        'is_active' => true,
                    ],
                );

                return [$attributes['code'] => $customer];
            });

            Promotion::firstOrCreate(
                ['code' => 'PROMO-MEMBER-FACIAL'],
                [
                    'name' => 'Member Facial Week',
                    'discount_type' => 'percent',
                    'discount_percent' => 10,
                    'starts_at' => today()->startOfMonth(),
                    'ends_at' => today()->endOfMonth(),
                    'members_only' => true,
                    'is_active' => true,
                ],
            );

            foreach ([
                ['code' => 'CASH', 'name' => 'Tunai', 'type' => 'cash', 'is_cash' => true, 'requires_reference' => false, 'sort_order' => 10],
                ['code' => 'QRIS_BCA', 'name' => 'QRIS BCA', 'type' => 'qris', 'is_cash' => false, 'requires_reference' => true, 'sort_order' => 20],
                ['code' => 'TRANSFER_BCA', 'name' => 'Transfer BCA', 'type' => 'bank_transfer', 'is_cash' => false, 'requires_reference' => true, 'sort_order' => 30],
                ['code' => 'DEBIT', 'name' => 'Kartu Debit', 'type' => 'card', 'is_cash' => false, 'requires_reference' => true, 'sort_order' => 40],
            ] as $attributes) {
                PaymentMethod::firstOrCreate(
                    ['code' => $attributes['code']],
                    [...$attributes, 'is_active' => true],
                );
            }

            $this->seedReservations($customers, $treatments, $employees);
            $this->seedPayrolls($employees);
        });
    }

    private function seedReservations($customers, $treatments, $employees): void
    {
        $adminId = DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        $today = today();

        $definitions = [
            ['code' => 'DEMO-BOOKING-001', 'queue' => 'A001', 'customer' => 'CUST-0001', 'time' => '09:00:00', 'status' => 'in_service', 'treatment' => 'TRT-HAIR-LOREAL', 'employee' => 'EMP-DITA', 'work_status' => 'in_progress'],
            ['code' => 'DEMO-BOOKING-002', 'queue' => 'A002', 'customer' => 'CUST-0002', 'time' => '10:30:00', 'status' => 'arrived', 'treatment' => 'TRT-FACIAL-BARRIER', 'employee' => 'EMP-RANI', 'work_status' => 'ready'],
            ['code' => 'DEMO-BOOKING-003', 'queue' => 'A003', 'customer' => 'CUST-0003', 'time' => '11:00:00', 'status' => 'scheduled', 'treatment' => 'TRT-CREAMBATH-MKRZ', 'employee' => 'EMP-MAYA', 'work_status' => 'waiting'],
            ['code' => 'DEMO-BOOKING-004', 'queue' => 'A004', 'customer' => 'CUST-0004', 'time' => '13:00:00', 'status' => 'scheduled', 'treatment' => 'TRT-NAIL-GEL-HAND', 'employee' => 'EMP-SARI', 'work_status' => 'waiting'],
        ];

        foreach ($definitions as $definition) {
            $treatment = $treatments[$definition['treatment']];
            $scheduledStart = $today->copy()->setTimeFromTimeString($definition['time']);
            $scheduledEnd = $scheduledStart->copy()->addMinutes($treatment->duration_minutes);
            $commissionRate = (int) str_replace('.', '', $treatment->default_commission_percent);
            $commissionAmount = intdiv(($treatment->normal_price * $commissionRate) + 500000, 1000000);

            $reservation = Reservation::firstOrCreate(
                ['booking_code' => $definition['code']],
                [
                    'queue_number' => $definition['queue'],
                    'customer_id' => $customers[$definition['customer']]->id,
                    'reservation_date' => $today,
                    'reservation_time' => $definition['time'],
                    'source' => 'walk_in',
                    'status' => $definition['status'],
                    'created_by' => $adminId,
                    'updated_by' => $adminId,
                ],
            );

            $item = ReservationItem::firstOrCreate(
                ['reservation_id' => $reservation->id, 'sort_order' => 1],
                [
                    'treatment_id' => $treatment->id,
                    'treatment_name' => $treatment->name,
                    'duration_minutes' => $treatment->duration_minutes,
                    'normal_price' => $treatment->normal_price,
                    'unit_price' => $treatment->normal_price,
                    'discount_percent' => 0,
                    'discount_amount' => 0,
                    'net_price' => $treatment->normal_price,
                    'commission_percent' => $treatment->default_commission_percent,
                    'commission_amount' => $commissionAmount,
                    'scheduled_start_at' => $scheduledStart,
                    'scheduled_end_at' => $scheduledEnd,
                    'started_at' => $definition['work_status'] === 'in_progress' ? $scheduledStart : null,
                    'ready_at' => $definition['work_status'] === 'ready' ? $scheduledStart : null,
                    'work_status' => $definition['work_status'],
                ],
            );

            ReservationItemStaff::firstOrCreate(
                ['reservation_item_id' => $item->id, 'employee_id' => $employees[$definition['employee']]->id],
                [
                    'role' => 'primary',
                    'commission_percent' => $item->commission_percent,
                    'commission_amount' => $item->commission_amount,
                ],
            );
        }
    }

    private function seedPayrolls($employees): void
    {
        foreach ([
            ['employee' => 'EMP-DITA', 'base' => 3200000, 'bonus' => 250000, 'late' => 75000, 'commission' => 865000, 'late_minutes' => 135],
            ['employee' => 'EMP-RANI', 'base' => 3200000, 'bonus' => 150000, 'late' => 25000, 'commission' => 720000, 'late_minutes' => 45],
            ['employee' => 'EMP-MAYA', 'base' => 3000000, 'bonus' => 0, 'late' => 40000, 'commission' => 635000, 'late_minutes' => 70],
            ['employee' => 'EMP-VINA', 'base' => 3000000, 'bonus' => 200000, 'late' => 20000, 'commission' => 0, 'late_minutes' => 30],
        ] as $definition) {
            $employee = $employees[$definition['employee']];
            Payroll::firstOrCreate(
                ['employee_id' => $employee->id, 'period' => today()->format('Y-m')],
                [
                    'employee_name' => $employee->name,
                    'position' => $employee->position,
                    'base_salary' => $definition['base'],
                    'bonus' => $definition['bonus'],
                    'late_deduction' => $definition['late'],
                    'commission' => $definition['commission'],
                    'late_duration_minutes' => $definition['late_minutes'],
                    'net_salary' => $definition['base'] + $definition['bonus'] + $definition['commission'] - $definition['late'],
                    'status' => 'draft',
                ],
            );
        }
    }
}
