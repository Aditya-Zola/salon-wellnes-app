<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalonOperationSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('treatments')->exists()) return;
        $now=now();
        foreach ([['Dita','Hair therapist'],['Rani','Beauty therapist'],['Maya','Hair therapist'],['Sari','Nail artist']] as [$name,$specialty]) DB::table('therapists')->insert(['name'=>$name,'specialty'=>$specialty,'active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        foreach ([['Skin Barrier Facial','Facial Ritual',60,95000,2],['L’Oréal Hair Spa','Healthy Hair',90,185000,2],['Makarizo Creambath','Hair Ritual',60,125000,2],['Lunara Package','Special Bundle',120,205000,2],['Nail Gel Polish - Hand','Luxe Nail',60,80000,2],['Wellness Spa','Wellness & Spa',60,145000,2]] as [$name,$category,$duration,$price,$commission]) DB::table('treatments')->insert(['name'=>$name,'category'=>$category,'duration_minutes'=>$duration,'price'=>$price,'commission_percent'=>$commission,'active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        foreach ([['Hair Spa L’Oréal','Hair',400,'gr',150,0],['ERHA Hair Growth Serum','Hair',120,'ml',75,0],['Skin Barrier Mask','Facial',18,'pcs',10,0],['Herbal Drink','Konsumsi',9,'sachet',12,10000]] as [$name,$category,$stock,$unit,$min,$price]) DB::table('products')->insert(['name'=>$name,'category'=>$category,'stock'=>$stock,'unit'=>$unit,'minimum_stock'=>$min,'selling_price'=>$price,'created_at'=>$now,'updated_at'=>$now]);
        foreach ([['Nadia Prameswari','081234567801',true,8],['Alya Putri','081377881204',true,5],['Siska Amanda','085722018890',true,3],['Rina Ayu','081211884420',false,0]] as [$name,$phone,$member,$visits]) DB::table('customers')->insert(['name'=>$name,'phone'=>$phone,'is_member'=>$member,'member_since'=>$member?today():null,'visit_count'=>$visits,'created_at'=>$now,'updated_at'=>$now]);
        DB::table('promotions')->insert(['name'=>'Member Facial Week','discount_percent'=>10,'starts_at'=>today()->startOfMonth(),'ends_at'=>today()->endOfMonth(),'members_only'=>true,'active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        $admin=DB::table('users')->where('email','admin@gmail.com')->value('id');
        $customers=DB::table('customers')->pluck('id');$treatments=DB::table('treatments')->pluck('id');$therapists=DB::table('therapists')->pluck('id');
        foreach ([['A001','09:00:00',0,1,0,'Sedang dilayani'],['A002','10:30:00',1,0,1,'Sudah datang'],['A003','11:00:00',2,2,2,'Terjadwal'],['A004','13:00:00',3,3,3,'Terjadwal']] as [$queue,$time,$ci,$ti,$hi,$status]) DB::table('reservations')->insert(['queue_number'=>$queue,'customer_id'=>$customers[$ci],'treatment_id'=>$treatments[$ti],'therapist_id'=>$therapists[$hi],'reservation_date'=>today(),'reservation_time'=>$time,'status'=>$status,'created_by'=>$admin,'created_at'=>$now,'updated_at'=>$now]);
        foreach ([['Dita','Hair therapist',3200000,250000,75000,865000,'2j 15m'],['Rani','Beauty therapist',3200000,150000,25000,720000,'45m'],['Maya','Hair therapist',3000000,0,40000,635000,'1j 10m'],['Vina','Marketing',3000000,200000,20000,0,'30m']] as [$name,$position,$base,$bonus,$late,$commission,$duration]) DB::table('payrolls')->insert(['employee_name'=>$name,'position'=>$position,'period'=>today()->format('Y-m'),'base_salary'=>$base,'bonus'=>$bonus,'late_deduction'=>$late,'commission'=>$commission,'late_duration'=>$duration,'created_at'=>$now,'updated_at'=>$now]);
    }
}
