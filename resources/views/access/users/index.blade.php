@extends('layouts.internal')

@section('title', 'Pengguna - Selesa Salon')
@section('heading', 'Pengguna')
@section('subtitle', 'Kelola akun pengguna dan pasangkan setiap akun ke satu peran.')

@section('header-action')
    @can('access.users.manage')
        <button type="button" class="access-button primary" id="open-user-modal">＋ Input pengguna baru</button>
    @endcan
@endsection

@section('content')
    <div class="access-grid">
        <section class="access-card">
            <div class="access-card-head">
                <div>
                    <h2>Daftar pengguna</h2>
                    <p>{{ $users->count() }} akun terdaftar.</p>
                </div>
            </div>
            <div class="access-table-wrap">
                <table class="access-table user-table">
                    <thead><tr><th>Pengguna</th><th>Peran</th><th class="align-right">Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <i>{{ strtoupper(substr($user->name, 0, 2)) }}</i>
                                    <span><strong>{{ $user->name }}</strong><small>{{ $user->email }}</small></span>
                                </div>
                            </td>
                            <td><span class="role-badge">{{ $user->role_name }}</span></td>
                            <td class="table-actions">
                                <a class="access-button secondary compact" href="{{ route('access.users.edit', $user) }}">Edit</a>
                                @can('access.users.manage')
                                    @if (! auth()->user()->is($user) && ! $user->isSuperAdmin())
                                        <form method="POST" action="{{ route('access.users.destroy', $user) }}" onsubmit="return confirm('Hapus pengguna ini?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="text-danger" type="submit">Hapus</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="empty-state">Belum ada pengguna.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @can('access.users.manage')
        <div class="modal {{ $errors->any() ? 'open' : '' }}" id="user-modal" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
            <div class="modal-box small">
                <div class="modal-head">
                    <div><h2 id="user-modal-title">Input pengguna baru</h2><p>Akun dapat langsung digunakan untuk masuk.</p></div>
                    <button type="button" class="user-modal-close" aria-label="Tutup">×</button>
                </div>
                <form method="POST" action="{{ route('access.users.store') }}" class="access-form">
                    @csrf
                    <label>Nama<input name="name" value="{{ old('name') }}" required maxlength="100" autofocus></label>
                    <label>Email<input type="email" name="email" value="{{ old('email') }}" required maxlength="150"></label>
                    <label>
                        Peran
                        <select name="role_id" required>
                            <option value="">Pilih peran</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->id }}" @selected((string) old('role_id') === (string) $role->id)>{{ $role->display_name ?: $role->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Kata sandi<input type="password" name="password" required minlength="8"></label>
                    <label>Konfirmasi kata sandi<input type="password" name="password_confirmation" required minlength="8"></label>
                    <footer>
                        <button type="button" class="access-button secondary user-modal-close">Batal</button>
                        <button class="access-button primary" type="submit">Simpan pengguna</button>
                    </footer>
                </form>
            </div>
        </div>
    @endcan
@endsection

@push('scripts')
    <script>
        const userModal = document.getElementById('user-modal');
        const closeUserModal = () => userModal?.classList.remove('open');

        document.getElementById('open-user-modal')?.addEventListener('click', () => userModal?.classList.add('open'));
        document.querySelectorAll('.user-modal-close').forEach((button) => button.addEventListener('click', closeUserModal));
        userModal?.addEventListener('click', (event) => {
            if (event.target === userModal) closeUserModal();
        });
    </script>
@endpush
