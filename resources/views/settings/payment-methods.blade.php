@extends('layouts.internal')

@section('title', $config['title'].' · Pengaturan · Selesa Salon')
@section('heading', 'Pengaturan '.$config['title'])
@section('subtitle', 'Kelola metode aktif yang muncul di kasir dan indikator dashboard.')

@section('content')
    @php($withAccount = in_array($section, ['bank', 'qris'], true))
    <section class="access-card payment-methods-card">
        <div class="access-card-head payment-methods-head">
            <div>
                <h2>Daftar {{ $config['title'] }}</h2>
                <p>Aktifkan metode yang siap dipakai. Hapus menghilangkan label dari daftar, tanpa mengubah riwayat transaksi.</p>
            </div>
            <div class="payment-methods-head-actions">
                <span class="count-badge">{{ $methods->count() }} metode</span>
                <button type="button" class="access-button primary" data-open-payment-method-modal>
                    <span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah {{ $config['title'] }}
                </button>
            </div>
        </div>

        <div class="access-table-wrap payment-methods-table-wrap">
            <table class="access-table payment-methods-table">
                <thead>
                    <tr>
                        <th scope="col">{{ $config['source_label'] }}</th>
                        @if($withAccount)<th scope="col">Akun tujuan</th>@endif
                        <th scope="col">Status</th>
                        <th scope="col" class="align-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($methods as $method)
                    <tr>
                        <td><strong>{{ $method->name }}</strong><small>{{ $withAccount ? 'Pembayaran '.$config['title'] : 'Pembayaran kartu / EDC' }}</small></td>
                        @if($withAccount)
                            <td><strong>{{ $method->account_name ?: '-' }}</strong><small>{{ $method->account_number ?: '-' }}</small></td>
                        @endif
                        <td><span class="role-badge {{ $method->is_active ? 'ui-status-success' : 'ui-status-neutral' }}">{{ $method->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                        <td class="align-right">
                            <div class="table-actions">
                                <a class="access-button compact ui-action-edit" href="{{ route('settings.payment-methods.index', [$section, 'edit' => $method->id]) }}"><span class="material-symbols-outlined" aria-hidden="true">edit</span><span class="payment-method-action-label">Edit</span></a>
                                <form method="POST" action="{{ route('settings.payment-methods.toggle', [$section, $method->id]) }}">
                                    @csrf @method('PATCH')
                                    <button class="access-button compact {{ $method->is_active ? 'secondary' : 'ui-action-success' }}" type="submit"><span class="material-symbols-outlined" aria-hidden="true">{{ $method->is_active ? 'pause_circle' : 'check_circle' }}</span><span class="payment-method-action-label">{{ $method->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</span></button>
                                </form>
                                <form method="POST" action="{{ route('settings.payment-methods.destroy', [$section, $method->id]) }}" data-delete-payment-method data-method-name="{{ $method->name }}">
                                    @csrf @method('DELETE')
                                    <button class="access-button compact ui-action-delete" type="submit"><span class="material-symbols-outlined" aria-hidden="true">delete</span><span class="payment-method-action-label">Hapus</span></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="empty-state" colspan="{{ $withAccount ? 4 : 3 }}">Belum ada {{ $config['title'] }}. Tambahkan metode pertama melalui tombol di atas.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="payment-method-modal" id="payment-method-modal" data-open="{{ $editMethod || $errors->any() ? 'true' : 'false' }}" hidden>
        <div class="payment-method-modal-backdrop" data-close-payment-method-modal></div>
        <section class="payment-method-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="payment-method-modal-title">
            <header>
                <div>
                    <h2 id="payment-method-modal-title">{{ $editMethod ? 'Ubah '.$config['title'] : 'Tambah '.$config['title'] }}</h2>
                    <p>{{ $editMethod ? 'Perbarui informasi metode pembayaran.' : 'Metode baru akan tersedia di kasir saat statusnya aktif.' }}</p>
                </div>
                <button type="button" class="payment-method-modal-close" data-close-payment-method-modal aria-label="Tutup dialog"><span class="material-symbols-outlined" aria-hidden="true">close</span></button>
            </header>
            <form method="POST" action="{{ $editMethod ? route('settings.payment-methods.update', [$section, $editMethod->id]) : route('settings.payment-methods.store', $section) }}">
                @csrf
                @if ($editMethod) @method('PATCH') @endif
                <div class="payment-method-form-grid">
                    <label class="payment-method-field-wide">{{ $config['source_label'] }}<input name="source_name" value="{{ old('source_name', $editMethod->name ?? '') }}" maxlength="100" required placeholder="Contoh: BCA"></label>
                    @if ($withAccount)
                        <label>Nama akun<input name="account_name" value="{{ old('account_name', $editMethod->account_name ?? '') }}" maxlength="150" required placeholder="Contoh: Selesa Salon"></label>
                        <label>Nomor rekening / ID merchant<input name="account_number" value="{{ old('account_number', $editMethod->account_number ?? '') }}" maxlength="100" required placeholder="Masukkan nomor tujuan"></label>
                    @endif
                    <label>Status metode<select name="is_active"><option value="1" @selected(old('is_active', $editMethod->is_active ?? true))>Aktif</option><option value="0" @selected(! old('is_active', $editMethod->is_active ?? true))>Nonaktif</option></select></label>
                </div>
                <footer>
                    <button type="button" class="access-button secondary" data-close-payment-method-modal>Batal</button>
                    <button class="access-button primary" type="submit"><span class="material-symbols-outlined" aria-hidden="true">{{ $editMethod ? 'save' : 'add' }}</span> {{ $editMethod ? 'Simpan perubahan' : 'Tambah '.$config['title'] }}</button>
                </footer>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
<script>
    (() => {
        const modal = document.getElementById('payment-method-modal');
        if (!modal) return;
        const open = () => { modal.hidden = false; document.body.classList.add('payment-method-modal-open'); modal.querySelector('input, select')?.focus(); };
        const close = () => { modal.hidden = true; document.body.classList.remove('payment-method-modal-open'); };
        document.querySelectorAll('[data-open-payment-method-modal]').forEach((button) => button.addEventListener('click', open));
        modal.querySelectorAll('[data-close-payment-method-modal]').forEach((button) => button.addEventListener('click', close));
        document.querySelectorAll('[data-delete-payment-method]').forEach((form) => form.addEventListener('submit', (event) => {
            if (!window.confirm(`Hapus ${form.dataset.methodName} dari daftar metode? Riwayat transaksi tetap menyimpan label ini.`)) event.preventDefault();
        }));
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && !modal.hidden) close(); });
        if (modal.dataset.open === 'true') open();
    })();
</script>
@endpush
