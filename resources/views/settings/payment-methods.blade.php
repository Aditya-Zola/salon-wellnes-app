@extends('layouts.internal')

@section('title', $config['title'].' · Pengaturan · Selesa Salon')
@section('heading', 'Pengaturan '.$config['title'])
@section('subtitle', 'Data aktif akan langsung tersedia sebagai pilihan pembayaran di kasir dan indikator dashboard.')

@section('content')
    @php($withAccount = in_array($section, ['bank', 'qris'], true))
    <div class="access-grid access-grid-sidebar">
        <section class="access-card form-card settings-method-form">
            <div class="access-card-head">
                <div>
                    <h2>{{ $editMethod ? 'Ubah '.$config['title'] : 'Tambah '.$config['title'] }}</h2>
                    <p>{{ $editMethod ? 'Perbarui data metode pembayaran.' : 'Tambahkan metode yang dapat dipilih kasir.' }}</p>
                </div>
            </div>
            <form class="access-form" method="POST" action="{{ $editMethod ? route('settings.payment-methods.update', [$section, $editMethod->id]) : route('settings.payment-methods.store', $section) }}">
                @csrf
                @if ($editMethod) @method('PATCH') @endif
                <label>{{ $config['source_label'] }}<input name="source_name" value="{{ old('source_name', $editMethod->name ?? '') }}" maxlength="100" required placeholder="Contoh: BCA"></label>
                @if ($withAccount)
                    <label>Nama akun<input name="account_name" value="{{ old('account_name', $editMethod->account_name ?? '') }}" maxlength="150" required></label>
                    <label>Nomor rekening / ID merchant<input name="account_number" value="{{ old('account_number', $editMethod->account_number ?? '') }}" maxlength="100" required></label>
                @endif
                <label>Charge pembayaran (%)<input name="charge_percent" type="number" min="0" max="100" step="0.0001" inputmode="decimal" value="{{ old('charge_percent', $editMethod->charge_percent ?? '0') }}"><small>Contoh: isi 2 untuk charge 2%. Charge tetap bisa dimatikan dari kasir.</small></label>
                <label>Charge default di kasir<select name="charge_default_enabled"><option value="1" @selected(old('charge_default_enabled', $editMethod->charge_default_enabled ?? true))>Aktif</option><option value="0" @selected(! old('charge_default_enabled', $editMethod->charge_default_enabled ?? true))>Nonaktif</option></select></label>
                <label>Status<select name="is_active"><option value="1" @selected(old('is_active', $editMethod->is_active ?? true))>Aktif</option><option value="0" @selected(! old('is_active', $editMethod->is_active ?? true))>Nonaktif</option></select></label>
                <div class="form-actions">
                    @if ($editMethod)<a class="access-button secondary" href="{{ route('settings.payment-methods.index', $section) }}">Batal</a>@endif
                    <button class="access-button primary" type="submit">{{ $editMethod ? 'Simpan perubahan' : 'Tambah '.$config['title'] }}</button>
                </div>
            </form>
        </section>

        <section class="access-card">
            <div class="access-card-head"><div><h2>Daftar {{ $config['title'] }}</h2><p>Nonaktifkan metode yang sementara tidak digunakan; riwayat transaksi tetap aman.</p></div><span class="count-badge">{{ $methods->count() }} metode</span></div>
            <div class="access-table-wrap">
                <table class="access-table">
                    <thead><tr><th>{{ $config['source_label'] }}</th>@if($withAccount)<th>Akun</th><th>Rekening / ID</th>@endif<th>Charge</th><th>Status</th><th class="align-right">Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($methods as $method)
                        <tr>
                            <td><strong>{{ $method->name }}</strong></td>
                            @if($withAccount)<td>{{ $method->account_name ?: '-' }}</td><td>{{ $method->account_number ?: '-' }}</td>@endif
                            <td>{{ rtrim(rtrim(number_format((float) $method->charge_percent, 4, '.', ''), '0'), '.') ?: '0' }}% {{ $method->charge_default_enabled ? '(default aktif)' : '(default nonaktif)' }}</td>
                            <td><span class="role-badge">{{ $method->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td class="align-right"><div class="table-actions"><a class="access-button compact secondary" href="{{ route('settings.payment-methods.index', [$section, 'edit' => $method->id]) }}">Edit</a><form method="POST" action="{{ route('settings.payment-methods.toggle', [$section, $method->id]) }}">@csrf @method('PATCH')<button class="text-button" type="submit">{{ $method->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></form></div></td>
                        </tr>
                    @empty
                        <tr><td class="empty-state" colspan="{{ $withAccount ? 6 : 4 }}">Belum ada {{ $config['title'] }}. Tambahkan dari formulir di samping.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
