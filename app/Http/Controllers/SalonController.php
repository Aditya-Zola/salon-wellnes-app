<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class SalonController extends Controller
{
    public function dashboard()
    {
        if (! Schema::hasTable('reservations')) return view('dashboard', ['salonData' => []]);
        return view('dashboard', ['salonData' => $this->snapshot()]);
    }

    public function data(): JsonResponse { return response()->json($this->snapshot()); }

    public function storeReservation(Request $request): JsonResponse
    {
        $data = $request->validate(['name'=>'required|string|max:100','phone'=>'required|string|max:30','date'=>'required|date','time'=>'required','treatment_id'=>'required|exists:treatments,id','therapist_id'=>'required|exists:therapists,id','notes'=>'nullable|string|max:1000']);
        $reservation = DB::transaction(function () use ($data, $request) {
            $customer = DB::table('customers')->where('phone',$data['phone'])->first();
            if ($customer) { DB::table('customers')->where('id',$customer->id)->update(['name'=>$data['name'],'updated_at'=>now()]); $customerId=$customer->id; }
            else $customerId=DB::table('customers')->insertGetId(['name'=>$data['name'],'phone'=>$data['phone'],'created_at'=>now(),'updated_at'=>now()]);
            $busy=$this->therapistIsBusy((int)$data['therapist_id'],(int)$data['treatment_id'],$data['date'],$data['time']);
            abort_if($busy,422,'Terapis sudah memiliki jadwal yang beririsan dengan waktu tersebut.');
            $number='TMP-'.str()->uuid();
            $id=DB::table('reservations')->insertGetId(['queue_number'=>$number,'customer_id'=>$customerId,'treatment_id'=>$data['treatment_id'],'therapist_id'=>$data['therapist_id'],'reservation_date'=>$data['date'],'reservation_time'=>$data['time'],'status'=>'Terjadwal','notes'=>$data['notes']??null,'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
            $this->reorderQueue($data['date']);
            $number=DB::table('reservations')->where('id',$id)->value('queue_number');
            $this->log($request,'reservasi.dibuat','reservation',$id,"Membuat reservasi {$number} untuk {$data['name']}"); return $id;
        });
        return response()->json(['message'=>'Reservasi berhasil dibuat','id'=>$reservation],201);
    }

    public function availableTherapists(Request $request): JsonResponse
    {
        $data=$request->validate(['date'=>'required|date','time'=>'required','treatment_id'=>'required|exists:treatments,id']);
        $therapists=DB::table('therapists')->where('active',true)->orderBy('name')->get()->map(function($therapist)use($data){
            $therapist->available=!$this->therapistIsBusy($therapist->id,(int)$data['treatment_id'],$data['date'],$data['time']);
            return $therapist;
        });
        return response()->json(['therapists'=>$therapists]);
    }

    public function updateReservation(Request $request, int $id): JsonResponse
    {
        $data=$request->validate(['status'=>['required',Rule::in(['Terjadwal','Sudah datang','Sedang dilayani','Selesai','Batal'])]]);
        $date=DB::table('reservations')->where('id',$id)->value('reservation_date');
        abort_unless($date,404);
        abort_unless(DB::table('reservations')->where('id',$id)->update(['status'=>$data['status'],'updated_at'=>now()]),404);
        $this->reorderQueue($date);
        $this->log($request,'reservasi.status','reservation',$id,"Mengubah status reservasi menjadi {$data['status']}");
        return response()->json(['message'=>'Status reservasi diperbarui']);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $d=$request->validate(['name'=>'required|string|max:150','category'=>'required|string|max:50','stock'=>'required|numeric|min:0','unit'=>'required|string|max:20','minimum_stock'=>'required|numeric|min:0','selling_price'=>'required|integer|min:0']);
        $id=DB::table('products')->insertGetId([...$d,'created_at'=>now(),'updated_at'=>now()]);
        if($d['stock']>0) DB::table('stock_movements')->insert(['product_id'=>$id,'type'=>'masuk','quantity'=>$d['stock'],'source'=>'Stok awal','created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);
        $this->log($request,'produk.dibuat','product',$id,"Menambahkan produk {$d['name']}");
        return response()->json(['message'=>'Produk berhasil ditambahkan','id'=>$id],201);
    }

    public function adjustStock(Request $request, int $id): JsonResponse
    {
        $d=$request->validate(['type'=>['required',Rule::in(['masuk','keluar','opname'])],'quantity'=>'required|numeric|min:0','source'=>'required|string|max:150']);
        DB::transaction(function()use($d,$id,$request){$p=DB::table('products')->lockForUpdate()->find($id);abort_unless($p,404);$new=$d['type']==='opname'?$d['quantity']:$p->stock+($d['type']==='masuk'?$d['quantity']:-$d['quantity']);abort_if($new<0,422,'Stok tidak mencukupi.');DB::table('products')->where('id',$id)->update(['stock'=>$new,'updated_at'=>now()]);DB::table('stock_movements')->insert(['product_id'=>$id,'type'=>$d['type'],'quantity'=>$d['type']==='opname'?abs($new-$p->stock):$d['quantity'],'source'=>$d['source'],'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);$this->log($request,'stok.diubah','product',$id,"Stok {$p->name} menjadi {$new} {$p->unit}");});
        return response()->json(['message'=>'Stok berhasil diperbarui']);
    }

    public function storeTreatment(Request $request): JsonResponse
    {
        $d=$request->validate(['name'=>'required|string|max:150','category'=>'required|string|max:80','duration_minutes'=>'required|integer|min:1','price'=>'required|integer|min:0','commission_percent'=>'required|numeric|min:0|max:100']);
        $id=DB::table('treatments')->insertGetId([...$d,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);$this->log($request,'treatment.dibuat','treatment',$id,"Menambahkan treatment {$d['name']}");return response()->json(['message'=>'Treatment berhasil ditambahkan','id'=>$id],201);
    }

    public function storeMember(Request $request): JsonResponse
    {
        $d=$request->validate(['name'=>'required|string|max:100','phone'=>'required|string|max:30']);$existing=DB::table('customers')->where('phone',$d['phone'])->first();
        if($existing){DB::table('customers')->where('id',$existing->id)->update(['name'=>$d['name'],'is_member'=>true,'member_since'=>$existing->member_since?:today(),'updated_at'=>now()]);$id=$existing->id;}else{$id=DB::table('customers')->insertGetId([...$d,'is_member'=>true,'member_since'=>today(),'created_at'=>now(),'updated_at'=>now()]);}
        $this->log($request,'member.diaktifkan','customer',$id,"Mengaktifkan membership {$d['name']}");return response()->json(['message'=>'Membership berhasil diaktifkan','id'=>$id],201);
    }

    public function updateRecipe(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('treatments')->where('id',$id)->exists(),404);
        $d=$request->validate(['product_id'=>'required|exists:products,id','quantity'=>'required|numeric|min:0.01']);
        DB::table('treatment_product')->updateOrInsert(['treatment_id'=>$id,'product_id'=>$d['product_id']],['quantity'=>$d['quantity']]);
        $this->log($request,'resep.diubah','treatment',$id,'Memperbarui komposisi produk treatment');
        return response()->json(['message'=>'Resep treatment berhasil diperbarui']);
    }

    public function storePayment(Request $request): JsonResponse
    {
        $d=$request->validate(['reservation_id'=>'required|exists:reservations,id','payment_method'=>['required',Rule::in(['Tunai','QRIS','Transfer','Kartu'])],'discount_percent'=>'nullable|numeric|min:0|max:100']);
        $transaction=DB::transaction(function()use($d,$request){$r=DB::table('reservations')->join('treatments','treatments.id','=','reservations.treatment_id')->join('customers','customers.id','=','reservations.customer_id')->where('reservations.id',$d['reservation_id'])->select('reservations.*','treatments.name as treatment_name','treatments.price','customers.is_member')->lockForUpdate()->first();abort_if($r->status==='Selesai',422,'Reservasi ini sudah dibayar.');$discount=(float)($d['discount_percent']??0);abort_if($discount>0&&!$r->is_member,422,'Diskon hanya dapat digunakan oleh member.');$amount=(int)round($r->price*$discount/100);$total=$r->price-$amount;$number='TRX-'.now()->format('Ymd').'-'.str_pad((string)(DB::table('transactions')->whereDate('created_at',today())->count()+1),3,'0',STR_PAD_LEFT);$id=DB::table('transactions')->insertGetId(['number'=>$number,'reservation_id'=>$r->id,'customer_id'=>$r->customer_id,'subtotal'=>$r->price,'discount_percent'=>$discount,'discount_amount'=>$amount,'total'=>$total,'payment_method'=>$d['payment_method'],'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);DB::table('transaction_items')->insert(['transaction_id'=>$id,'item_type'=>'treatment','item_id'=>$r->treatment_id,'name'=>$r->treatment_name,'quantity'=>1,'price'=>$r->price,'total'=>$r->price]);$recipes=DB::table('treatment_product')->join('products','products.id','=','treatment_product.product_id')->where('treatment_id',$r->treatment_id)->select('products.*','treatment_product.quantity as used')->get();foreach($recipes as $p){abort_if($p->stock<$p->used,422,"Stok {$p->name} tidak mencukupi.");DB::table('products')->where('id',$p->id)->decrement('stock',$p->used);DB::table('stock_movements')->insert(['product_id'=>$p->id,'type'=>'keluar','quantity'=>$p->used,'source'=>'Treatment','reference'=>$number,'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);}DB::table('reservations')->where('id',$r->id)->update(['status'=>'Selesai','updated_at'=>now()]);DB::table('customers')->where('id',$r->customer_id)->increment('visit_count');DB::table('cash_entries')->insert(['type'=>'masuk','category'=>'Penjualan','description'=>$number,'amount'=>$total,'entry_date'=>today(),'created_by'=>$request->user()->id,'created_at'=>now(),'updated_at'=>now()]);$this->log($request,'transaksi.selesai','transaction',$id,"Menyelesaikan transaksi {$number}");return ['id'=>$id,'number'=>$number,'total'=>$total];});
        return response()->json(['message'=>'Pembayaran berhasil diproses',...$transaction],201);
    }

    public function updatePayroll(Request $request, int $id): JsonResponse
    {
        $d=$request->validate(['base_salary'=>'required|integer|min:0','bonus'=>'required|integer|min:0','late_deduction'=>'required|integer|min:0','late_duration'=>'nullable|string|max:50']);abort_unless(DB::table('payrolls')->where('id',$id)->update([...$d,'updated_at'=>now()]),404);$this->log($request,'gaji.diubah','payroll',$id,'Memperbarui komponen gaji karyawan');return response()->json(['message'=>'Data gaji berhasil diperbarui']);
    }

    private function snapshot(): array
    {
        return ['reservations'=>DB::table('reservations')->join('customers','customers.id','=','reservations.customer_id')->join('treatments','treatments.id','=','reservations.treatment_id')->join('therapists','therapists.id','=','reservations.therapist_id')->select('reservations.*','customers.name as customer_name','customers.phone','customers.is_member','treatments.name as treatment_name','treatments.price','therapists.name as therapist_name')->orderByDesc('reservation_date')->orderBy('reservation_time')->get(),'treatments'=>DB::table('treatments')->where('active',true)->get(),'therapists'=>DB::table('therapists')->where('active',true)->get(),'members'=>DB::table('customers')->where('is_member',true)->get(),'products'=>DB::table('products')->get(),'stock_movements'=>DB::table('stock_movements')->join('products','products.id','=','stock_movements.product_id')->leftJoin('users','users.id','=','stock_movements.created_by')->select('stock_movements.*','products.name as product_name','products.unit','users.name as user_name')->latest('stock_movements.created_at')->limit(30)->get(),'transactions'=>DB::table('transactions')->leftJoin('customers','customers.id','=','transactions.customer_id')->select('transactions.*','customers.name as customer_name')->latest('transactions.created_at')->limit(20)->get(),'payrolls'=>DB::table('payrolls')->get(),'activities'=>DB::table('activity_logs')->leftJoin('users','users.id','=','activity_logs.user_id')->select('activity_logs.*','users.name as user_name')->latest('activity_logs.created_at')->limit(30)->get(),'promotions'=>DB::table('promotions')->where('active',true)->get()];
    }

    private function therapistIsBusy(int $therapistId,int $treatmentId,string $date,string $time): bool
    {
        $duration=(int)DB::table('treatments')->where('id',$treatmentId)->value('duration_minutes');
        $requestedStart=Carbon::parse("{$date} {$time}");
        $requestedEnd=$requestedStart->copy()->addMinutes($duration);
        return DB::table('reservations')->join('treatments','treatments.id','=','reservations.treatment_id')->where('reservations.therapist_id',$therapistId)->whereDate('reservations.reservation_date',$date)->whereNotIn('reservations.status',['Batal','Selesai'])->select('reservations.reservation_time','treatments.duration_minutes')->get()->contains(function($reservation)use($date,$requestedStart,$requestedEnd){
            $start=Carbon::parse("{$date} {$reservation->reservation_time}");
            $end=$start->copy()->addMinutes((int)$reservation->duration_minutes);
            return $start->lt($requestedEnd)&&$end->gt($requestedStart);
        });
    }

    private function reorderQueue(string $date): void
    {
        $allIds=DB::table('reservations')->whereDate('reservation_date',$date)->pluck('id');
        foreach($allIds as $id)DB::table('reservations')->where('id',$id)->update(['queue_number'=>'TMP-'.$id]);
        $activeIds=DB::table('reservations')->whereDate('reservation_date',$date)->where('status','!=','Batal')->orderBy('reservation_time')->orderBy('created_at')->orderBy('id')->pluck('id');
        foreach($activeIds as $index=>$id)DB::table('reservations')->where('id',$id)->update(['queue_number'=>'A'.str_pad((string)($index+1),3,'0',STR_PAD_LEFT)]);
        DB::table('reservations')->whereDate('reservation_date',$date)->where('status','Batal')->orderBy('reservation_time')->get()->each(fn($row)=>DB::table('reservations')->where('id',$row->id)->update(['queue_number'=>'B'.str_pad((string)$row->id,3,'0',STR_PAD_LEFT)]));
    }

    private function log(Request $request,string $action,string $type,?int $id,string $description): void { DB::table('activity_logs')->insert(['user_id'=>$request->user()?->id,'action'=>$action,'subject_type'=>$type,'subject_id'=>$id,'description'=>$description,'created_at'=>now(),'updated_at'=>now()]); }
}
