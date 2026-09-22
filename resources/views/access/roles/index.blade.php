@extends('layouts.internal')

@section('title', 'Peran - Selesa Salon')
@section('heading', 'Peran')
@section('subtitle', 'Buat peran dan tentukan menu serta tindakan yang dapat digunakan.')

@section('header-action')
    <div class="ui-header-actions">
        @can('access.roles.manage')
            <button type="button" class="access-button primary" id="open-role-modal"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah peran</button>
        @endcan
        <a class="access-button secondary" href="{{ route('dashboard') }}"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Halaman utama</a>
    </div>
@endsection

@section('content')
    <div class="access-grid">
        <section class="access-card">
            <div class="access-card-head">
                <div>
                    <h2>Daftar peran</h2>
                    <p>{{ $roles->count() }} peran tersedia di sistem.</p>
                </div>
            </div>

            <div class="access-table-wrap">
                <table class="access-table">
                    <thead>
                    <tr>
                        <th scope="col">Peran</th>
                        <th scope="col">Hak akses</th>
                        <th scope="col">Pengguna</th>
                        <th scope="col" class="align-right">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($roles as $role)
                        <tr>
                            <td>
                                <strong>{{ $role->display_name ?: $role->name }}</strong>
                                <small>{{ $role->is_system ? 'Peran bawaan sistem' : 'Peran buatan pengguna' }}</small>
                            </td>
                            <td><span class="count-badge">{{ $role->permissions_count }}</span></td>
                            <td>{{ $role->users_count }} orang</td>
                            <td class="align-right"><div class="table-actions">
                                <a class="access-button action-edit ui-action-edit compact" href="{{ route('access.roles.edit', $role) }}"><span class="material-symbols-outlined" aria-hidden="true">tune</span> Atur akses</a>
                                @can('access.roles.manage')
                                    @if (! $role->is_system && $role->users_count === 0)
                                        <form method="POST" action="{{ route('access.roles.destroy', $role) }}" onsubmit="return confirm('Hapus peran ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="access-button action-delete ui-action-delete compact" type="submit"><span class="material-symbols-outlined" aria-hidden="true">delete</span> Hapus</button>
                                        </form>
                                    @endif
                                @endcan
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="empty-state">Belum ada peran.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @can('access.roles.manage')
        <div class="modal {{ $errors->any() ? 'open' : '' }}" id="role-modal" role="dialog" aria-modal="true" aria-labelledby="role-modal-title">
            <div class="modal-box small">
                <div class="modal-head">
                    <div><h2 id="role-modal-title">Input peran baru</h2><p>Tentukan nama peran. Hak akses dapat diatur setelah disimpan.</p></div>
                    <button type="button" class="role-modal-close material-symbols-outlined" aria-label="Tutup">close</button>
                </div>
                <form method="POST" action="{{ route('access.roles.store') }}" class="access-form">
                    @csrf
                    <label>
                        Nama peran
                        <input name="display_name" value="{{ old('display_name') }}" placeholder="Contoh: Terapis" required maxlength="80" autofocus>
                    </label>
                    <footer>
                        <button type="button" class="access-button secondary role-modal-close">Batal</button>
                        <button class="access-button primary" type="submit">Simpan peran</button>
                    </footer>
                </form>
            </div>
        </div>
    @endcan
@endsection

@push('scripts')
    <script>
        const roleModal = document.getElementById('role-modal');
        const roleModalTrigger = document.getElementById('open-role-modal');
        const closeRoleModal = () => {
            roleModal?.classList.remove('open');
            roleModalTrigger?.focus();
        };

        roleModalTrigger?.addEventListener('click', () => {
            roleModal?.classList.add('open');
            roleModal?.querySelector('input[name="display_name"]')?.focus();
        });
        document.querySelectorAll('.role-modal-close').forEach((button) => button.addEventListener('click', closeRoleModal));
        roleModal?.addEventListener('click', (event) => {
            if (event.target === roleModal) closeRoleModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && roleModal?.classList.contains('open')) closeRoleModal();
        });
    </script>
@endpush
