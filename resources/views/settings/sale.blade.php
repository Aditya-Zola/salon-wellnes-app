@extends('layouts.internal')

@section('title', 'Pengaturan Penjualan · Selesa Salon')
@section('heading', 'Pengaturan Penjualan')
@section('subtitle', 'Atur format invoice serta informasi salon yang dicetak pada nota dan struk.')

@section('content')
    <section class="access-card settings-card">
        <div class="access-card-head">
            <div>
                <h2>Penjualan</h2>
                <p>Prefix digunakan di awal nomor invoice, misalnya INV20260814001. Informasi salon dipakai pada cetak ulang nota.</p>
            </div>
        </div>
        <form class="access-form settings-form" method="POST" action="{{ route('settings.sale.update') }}">
            @csrf
            @method('PATCH')
            <label>
                Prefix invoice
                <input name="invoice_prefix" value="{{ old('invoice_prefix', $invoicePrefix) }}" maxlength="20" required autofocus>
                <small>Gunakan huruf dan angka tanpa spasi atau tanda hubung.</small>
            </label>
            <label>
                Alamat salon
                <input name="salon_address" value="{{ old('salon_address', $salonAddress) }}" maxlength="255" placeholder="Alamat yang tercetak pada struk">
            </label>
            <label>
                Nomor WhatsApp salon
                <input name="salon_whatsapp" value="{{ old('salon_whatsapp', $salonWhatsapp) }}" maxlength="30" inputmode="tel" placeholder="Contoh: 08123456789">
                <small>Menjadi nomor WhatsApp default saat nota dibagikan dari kasir.</small>
            </label>
            <div class="form-actions">
                <button class="access-button primary" type="submit">Simpan pengaturan</button>
            </div>
        </form>
    </section>
@endsection
