<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalonOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_create_reservation_and_update_its_status(): void
    {
        $admin=User::where('email','admin@gmail.com')->firstOrFail();
        $treatment=\DB::table('treatments')->first();
        $therapist=\DB::table('therapists')->first();

        $response=$this->actingAs($admin)->postJson('/operasional/reservasi',[
            'name'=>'Pelanggan Baru','phone'=>'081299999999','date'=>today()->addDay()->toDateString(),
            'time'=>'09:30','treatment_id'=>$treatment->id,'therapist_id'=>$therapist->id,'notes'=>'Tes',
        ]);

        $response->assertCreated();
        $this->actingAs($admin)->patchJson('/operasional/reservasi/'.$response->json('id'),['status'=>'Sudah datang'])->assertOk();
        $this->assertDatabaseHas('reservations',['id'=>$response->json('id'),'status'=>'Sudah datang']);
        $this->assertDatabaseHas('activity_logs',['subject_type'=>'reservation','subject_id'=>$response->json('id')]);
    }

    public function test_marketing_can_create_product_and_adjust_stock(): void
    {
        $marketing=User::where('email','marketing@gmail.com')->firstOrFail();
        $response=$this->actingAs($marketing)->postJson('/operasional/produk',[
            'name'=>'Produk Tes','category'=>'Hair','stock'=>100,'unit'=>'ml','minimum_stock'=>20,'selling_price'=>15000,
        ]);
        $response->assertCreated();
        $this->actingAs($marketing)->patchJson('/operasional/produk/'.$response->json('id').'/stok',['type'=>'keluar','quantity'=>10,'source'=>'Tes pemakaian'])->assertOk();
        $this->assertDatabaseHas('products',['id'=>$response->json('id'),'stock'=>90]);
    }

    public function test_cashier_can_complete_member_payment(): void
    {
        $cashier=User::where('email','kasir@gmail.com')->firstOrFail();
        $reservation=\DB::table('reservations')->join('customers','customers.id','=','reservations.customer_id')->where('customers.is_member',true)->select('reservations.id')->first();
        $this->actingAs($cashier)->postJson('/operasional/pembayaran',['reservation_id'=>$reservation->id,'payment_method'=>'QRIS','discount_percent'=>10])->assertCreated();
        $this->assertDatabaseHas('reservations',['id'=>$reservation->id,'status'=>'Selesai']);
        $this->assertDatabaseCount('transactions',1);
        $this->assertDatabaseCount('cash_entries',1);
    }

    public function test_therapist_is_unavailable_only_when_reservation_times_overlap(): void
    {
        $admin=User::where('email','admin@gmail.com')->firstOrFail();
        $treatment=\DB::table('treatments')->where('duration_minutes',60)->first();
        $therapist=\DB::table('therapists')->first();
        $date=today()->addDays(2)->toDateString();
        $payload=['name'=>'Pelanggan Satu','phone'=>'081200000001','date'=>$date,'time'=>'10:00','treatment_id'=>$treatment->id,'therapist_id'=>$therapist->id];

        $this->actingAs($admin)->postJson('/operasional/reservasi',$payload)->assertCreated();
        $this->actingAs($admin)->postJson('/operasional/reservasi',[...$payload,'name'=>'Bentrok','phone'=>'081200000002','time'=>'10:30'])->assertStatus(422);
        $this->actingAs($admin)->postJson('/operasional/reservasi',[...$payload,'name'=>'Tidak Bentrok','phone'=>'081200000003','time'=>'11:00'])->assertCreated();

        $availability=$this->actingAs($admin)->getJson("/operasional/reservasi/terapis-tersedia?date={$date}&time=10:30&treatment_id={$treatment->id}")->assertOk();
        $this->assertFalse(collect($availability->json('therapists'))->firstWhere('id',$therapist->id)['available']);
    }

    public function test_queue_numbers_follow_reservation_time_for_each_date(): void
    {
        $admin=User::where('email','admin@gmail.com')->firstOrFail();
        $treatment=\DB::table('treatments')->first();
        $therapists=\DB::table('therapists')->take(2)->get();
        $date=today()->addDays(3)->toDateString();

        $late=$this->actingAs($admin)->postJson('/operasional/reservasi',['name'=>'Jam Siang','phone'=>'081211111111','date'=>$date,'time'=>'14:00','treatment_id'=>$treatment->id,'therapist_id'=>$therapists[0]->id])->assertCreated();
        $early=$this->actingAs($admin)->postJson('/operasional/reservasi',['name'=>'Jam Pagi','phone'=>'081222222222','date'=>$date,'time'=>'09:00','treatment_id'=>$treatment->id,'therapist_id'=>$therapists[1]->id])->assertCreated();

        $this->assertDatabaseHas('reservations',['id'=>$early->json('id'),'queue_number'=>'A001']);
        $this->assertDatabaseHas('reservations',['id'=>$late->json('id'),'queue_number'=>'A002']);
    }
}
