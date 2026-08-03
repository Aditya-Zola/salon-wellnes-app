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
}
