@extends('layouts.internal')

@section('title', 'Riwayat Reservasi Dibatalkan - Selesa Salon')
@section('heading', 'Riwayat Reservasi Dibatalkan')
@section('subtitle', 'Pembatalan seluruh reservasi dan sebagian treatment')
@section('header-action')
    <a class="btn btn-secondary" href="{{ route('dashboard') }}#reservasi-antrean"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Antrean</a>
@endsection

@section('content')
    <section class="card gap-4 border border-base-300 bg-base-100 p-5 shadow-sm">
        <form method="GET" class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(12rem,2fr)_repeat(3,minmax(8rem,1fr))_auto]">
            <label>Cari reservasi<input class="input w-full" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Nomor atau pelanggan"></label>
            <label>Dibatalkan dari<input class="input w-full" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
            <label>Sampai tanggal<input class="input w-full" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
            <label>Jenis pembatalan<select class="select w-full" name="type"><option value="">Semua</option><option value="full" @selected(($filters['type'] ?? '') === 'full')>Seluruh reservasi</option><option value="partial" @selected(($filters['type'] ?? '') === 'partial')>Sebagian treatment</option></select></label>
            <div class="flex gap-2"><button class="btn btn-primary">Cari</button>@if(count(array_filter($filters)))<a class="btn btn-info" href="{{ route('reservations.cancelled') }}">Reset</a>@endif</div>
        </form>
    </section>
    <section class="card gap-4 border border-base-300 bg-base-100 p-5 shadow-sm">
        <div class="overflow-x-auto">
            <table class="table table-zebra w-full">
                <thead class="bg-primary/10 text-primary"><tr><th>Reservasi / jadwal</th><th>Pelanggan</th><th>Pembatalan</th><th>Treatment / alasan</th><th>Waktu / petugas</th><th>Pengembalian DP</th></tr></thead>
                <tbody>
                @forelse($reservations as $reservation)
                    <tr>
                        <td><b>{{ $reservation->queue_number ?: $reservation->booking_code }}</b><small class="mt-1 block whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($reservation->reservation_date)->locale('id')->translatedFormat('d M Y') }} · {{ substr($reservation->reservation_time, 0, 5) }}</small></td>
                        <td class="min-w-32 font-medium">{{ $reservation->customer_name }}</td>
                        <td><span class="badge h-auto whitespace-normal py-1 {{ $reservation->status === 'cancelled' ? 'badge-error' : 'badge-warning' }}">{{ $reservation->status === 'cancelled' ? 'Seluruh reservasi' : 'Sebagian treatment' }}</span>@if($reservation->cancellation_number)<small class="mt-2 block font-medium">{{ $reservation->cancellation_number }}</small>@endif</td>
                        <td class="min-w-56">
                            @foreach($reservation->cancelled_items as $item)
                                <div class="py-1"><b>{{ $item->treatment_name }}</b>@if($reservation->status !== 'cancelled')<small class="mt-1 block">{{ $item->cancellation_number }} · {{ $item->reason ?: 'Alasan tidak tercatat' }}@if($item->actor) · {{ $item->actor }}@endif</small>@endif</div>
                            @endforeach
                            @if($reservation->status === 'cancelled')<small class="mt-1 block">{{ $reservation->cancellation_reason ?: 'Alasan tidak tercatat' }}</small>@endif
                        </td>
                        <td><span class="whitespace-nowrap">{{ $reservation->cancelled_at ? \Carbon\CarbonImmutable::parse($reservation->cancelled_at)->locale('id')->translatedFormat('d M Y, H:i') : '-' }}</span><small class="mt-1 block">{{ $reservation->cancelled_by_name ?: ($reservation->status !== 'cancelled' ? 'Lihat petugas per treatment' : '-') }}</small></td>
                        <td class="min-w-56">
                            @if($reservation->status !== 'cancelled' || !$reservation->deposit_amount)
                                <span class="text-base-content/60">{{ $reservation->deposit_amount ? 'DP tetap untuk treatment aktif' : 'Tidak ada DP diterima' }}</span>
                            @elseif($refund = $reservation->deposit_refund)
                                <span class="badge badge-success h-auto whitespace-normal py-1">Sudah dikembalikan</span>
                                <b class="mt-2 block">Rp {{ number_format($refund->amount, 0, ',', '.') }}</b>
                                <small class="mt-1 block">{{ $refund->payment_method_name }} · {{ \Carbon\CarbonImmutable::parse($refund->refunded_at)->locale('id')->translatedFormat('d M Y, H:i') }}</small>
                                <small class="block">Kasir: {{ $refund->cashier_name }}</small>
                                @if($refund->reference_number)<small class="block">Ref: {{ $refund->reference_number }}</small>@endif
                            @else
                                <span class="badge badge-warning h-auto whitespace-normal py-1">Menunggu pengembalian</span>
                                <b class="mt-2 block">Rp {{ number_format($reservation->deposit_amount, 0, ',', '.') }}</b>
                                @can('cashier.process')
                                    <details class="mt-2">
                                        <summary class="btn btn-sm btn-primary">Kembalikan DP</summary>
                                        <form method="POST" action="{{ route('reservations.deposit-refund', $reservation->id) }}" class="mt-3 grid gap-3 rounded-box border border-base-300 p-3">
                                            @csrf
                                            <label>Jenis bayar<select class="select select-sm w-full" name="payment_method_id" required>
                                                <option value="">Pilih jenis bayar</option>
                                                @foreach($refundMethods as $method)<option value="{{ $method->id }}">{{ $method->name }}{{ $method->requires_reference ? ' (referensi wajib)' : '' }}</option>@endforeach
                                            </select></label>
                                            <label>Referensi<input class="input input-sm w-full" name="reference_number" maxlength="255"></label>
                                            <p class="text-xs text-base-content/70">Konfirmasi setelah seluruh DP dikembalikan kepada pelanggan.</p>
                                            <button class="btn btn-sm btn-success h-auto min-h-10 whitespace-normal py-2 leading-snug">Konfirmasi pengembalian</button>
                                        </form>
                                    </details>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-12 text-center text-base-content/60">Belum ada pembatalan yang sesuai.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 pt-4 text-sm">
            <span class="text-base-content/60">{{ $reservations->firstItem() ?? 0 }}–{{ $reservations->lastItem() ?? 0 }} dari {{ $reservations->total() }} reservasi</span>
            @if($reservations->hasPages())<nav class="flex flex-wrap items-center gap-2" aria-label="Halaman pembatalan">
                @if(!$reservations->onFirstPage())<a class="btn btn-sm btn-secondary" href="{{ $reservations->previousPageUrl() }}">Sebelumnya</a>@endif
                <span>{{ $reservations->currentPage() }} / {{ $reservations->lastPage() }}</span>
                @if($reservations->hasMorePages())<a class="btn btn-sm btn-primary" href="{{ $reservations->nextPageUrl() }}">Berikutnya</a>@endif
            </nav>@endif
        </div>
    </section>
@endsection
