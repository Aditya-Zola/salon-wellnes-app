@extends('layouts.internal')

@section('title', 'Peran - Selesa Salon')
@section('heading', 'Peran')
@section('subtitle', 'Buat peran dan tentukan menu serta tindakan yang dapat digunakan.')

@section('header-action')
    <div style="display: flex; gap: 9px; margin-left: auto;">
        @can('access.roles.manage')
            <button type="button" class="access-button primary" id="open-role-modal"><span class="material-symbols-outlined" aria-hidden="true">add</span> Input peran baru</button>
        @endcan
        <a class="access-button secondary" href="{{ route('dashboard') }}">Kembali ke halaman utama</a>
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
                        <th>Peran</th>
                        <th>Hak akses</th>
                        <th>Pengguna</th>
                        <th class="align-right">Aksi</th>
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
                            <td class="table-actions">
                                <a class="access-button secondary compact" href="{{ route('access.roles.edit', $role) }}">Atur akses</a>
                                @can('access.roles.manage')
                                    @if (! $role->is_system && $role->users_count === 0)
                                        <form method="POST" action="{{ route('access.roles.destroy', $role) }}" onsubmit="return confirm('Hapus peran ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-danger" type="submit">Hapus</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
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
                    <div><h2 id="role-modal-title">Input peran baru</h2><p>Contoh: Terapis, Supervisor, atau Finance.</p></div>
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
        const closeRoleModal = () => roleModal?.classList.remove('open');

        document.getElementById('open-role-modal')?.addEventListener('click', () => roleModal?.classList.add('open'));
        document.querySelectorAll('.role-modal-close').forEach((button) => button.addEventListener('click', closeRoleModal));
        roleModal?.addEventListener('click', (event) => {
            if (event.target === roleModal) closeRoleModal();
        });
    </script>
@endpush
