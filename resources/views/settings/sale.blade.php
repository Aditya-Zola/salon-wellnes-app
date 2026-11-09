@extends('layouts.internal')

@section('title', 'Pengaturan Penjualan · Selesa Salon')
@section('heading', 'Pengaturan Penjualan')
@section('subtitle', 'Format nomor dokumen dan informasi salon')

@section('content')
    <section class="access-card settings-card card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
        <div class="access-card-head flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2>Nomor dokumen & informasi salon</h2>
            </div>
        </div>
        <form class="access-form settings-form grid gap-4 max-w-4xl" method="POST" action="{{ route('settings.sale.update') }}">
            @csrf
            @method('PATCH')
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach(['invoice_prefix' => ['Prefix invoice', $invoicePrefix], 'deposit_prefix' => ['Prefix DP', $depositPrefix], 'return_prefix' => ['Prefix retur', $returnPrefix], 'cancellation_prefix' => ['Prefix pembatalan', $cancellationPrefix]] as $key => [$label, $value])
                    <label>{{ $label }}<input class="input w-full min-w-0" name="{{ $key }}" value="{{ old($key, $value) }}" maxlength="20" pattern="[A-Za-z0-9]+" required><small>Contoh: {{ $value }}{{ now()->format('Ymd') }}001</small></label>
                @endforeach
            </div>
            <p class="text-xs text-base-content/60">Berlaku untuk dokumen baru. Nomor dokumen sebelumnya tetap.</p>
            <label>
                Alamat salon
                <input class="input w-full min-w-0" name="salon_address" value="{{ old('salon_address', $salonAddress) }}" maxlength="255" placeholder="Alamat yang tercetak pada struk">
            </label>
            <label>
                Nomor WhatsApp salon
                <input class="input w-full min-w-0" name="salon_whatsapp" value="{{ old('salon_whatsapp', $salonWhatsapp) }}" maxlength="30" inputmode="tel" placeholder="Contoh: 08123456789">
                <small>Menjadi nomor WhatsApp default saat nota dibagikan dari kasir.</small>
            </label>
            <div class="form-actions col-span-full flex flex-wrap justify-end gap-3 pt-4">
                <button class="access-button primary btn btn-primary" type="submit"><span class="material-symbols-outlined" aria-hidden="true">save</span> Simpan pengaturan</button>
            </div>
        </form>
    </section>
@endsection
