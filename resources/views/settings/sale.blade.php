@extends('layouts.internal')

@section('title', 'Pengaturan Penjualan · Selesa Salon')
@section('heading', 'Pengaturan Penjualan')
@section('subtitle', 'Atur format nomor invoice untuk seluruh transaksi baru.')

@section('content')
    <section class="access-card settings-card">
        <div class="access-card-head">
            <div>
                <h2>Penjualan</h2>
                <p>Prefix digunakan di awal nomor invoice, misalnya INV-20260814-001.</p>
            </div>
        </div>
        <form class="access-form settings-form" method="POST" action="{{ route('settings.sale.update') }}">
            @csrf
            @method('PATCH')
            <label>
                Prefix invoice
                <input name="invoice_prefix" value="{{ old('invoice_prefix', $invoicePrefix) }}" maxlength="20" required autofocus>
                <small>Gunakan huruf, angka, tanda hubung, atau underscore.</small>
            </label>
            <div class="form-actions">
                <button class="access-button primary" type="submit">Simpan pengaturan</button>
            </div>
        </form>
    </section>
@endsection
