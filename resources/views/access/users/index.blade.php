@extends('layouts.internal')

@section('title', 'Pengguna & Karyawan - Selesa Salon')
@section('heading', 'Pengguna & Karyawan')
@section('subtitle', 'Satu daftar untuk semua karyawan. Terapis tercatat tanpa akses login.')

@section('header-action')
    @can('access.users.manage')
        <button type="button" class="access-button primary btn btn-primary" id="open-user-modal"><span class="material-symbols-outlined" aria-hidden="true">add</span> Tambah pengguna</button>
    @endcan
@endsection

@section('content')
    <div class="access-grid grid gap-5">
        <section class="access-card card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
            <div class="access-card-head flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2>Daftar pengguna & karyawan</h2>
                    <p>{{ $users->count() + $employeesWithoutAccount->count() }} karyawan tercatat · {{ $users->count() }} memiliki akses login.</p>
                </div>
            </div>
            <div class="access-table-wrap overflow-x-auto">
                <table class="access-table user-table table table-zebra">
                    <thead class="bg-primary/10 text-xs text-primary"><tr><th>Karyawan</th><th>Akses sistem</th><th>Layanan</th><th>Status</th><th class="align-right text-right tabular-nums">Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($users as $user)
                        @php($employee = $user->employee)
                        <tr>
                            <td>
                                <div class="user-cell flex items-center gap-3">
                                    <i class="grid size-10 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-semibold not-italic text-primary">{{ strtoupper(substr($user->name, 0, 2)) }}</i>
                                    <span><strong>{{ $user->name }}</strong><small>{{ $employee?->position ?: 'Pengguna sistem' }} · &#64;{{ $user->username }}</small></span>
                                </div>
                            </td>
                            <td><span class="role-badge badge badge-primary badge-soft">{{ $user->role_name }}</span><small>&#64;{{ $user->username }}</small></td>
                            <td>{{ $employee?->is_service_provider ? 'Therapist' : 'Non-layanan' }}</td>
                            <td><span class="role-badge {{ ! $employee || $employee->active ? 'badge-success' : 'badge-ghost' }} badge badge-soft">{{ ! $employee || $employee->active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td class="align-right text-right tabular-nums"><div class="table-actions flex flex-wrap items-center justify-end gap-2">
                                <a class="access-button action-edit ui-action-edit compact btn btn-sm btn-accent" href="{{ route('access.users.edit', $user) }}">Edit</a>
                                @can('access.users.manage')
                                    @if (! auth()->user()->is($user) && ! $user->isSuperAdmin())
                                        <form method="POST" action="{{ route('access.users.destroy', $user) }}" onsubmit="return confirm('Hapus akun login ini? Data karyawannya tetap tersimpan untuk histori.')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="access-button action-delete ui-action-delete compact btn btn-sm btn-error" type="submit">Hapus akun</button>
                                        </form>
                                    @endif
                                @endcan
                            </div></td>
                        </tr>
                    @empty
                    @endforelse
                    @forelse ($employeesWithoutAccount as $employee)
                        <tr>
                            <td>
                                <div class="user-cell flex items-center gap-3">
                                    <i class="grid size-10 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-semibold not-italic text-primary">{{ strtoupper(substr($employee->name, 0, 2)) }}</i>
                                    <span><strong>{{ $employee->name }}</strong><small>{{ $employee->position ?: 'Karyawan' }} · {{ $employee->code }}</small></span>
                                </div>
                            </td>
                            <td><span class="employee-without-login">Tanpa akses login</span></td>
                            <td>{{ $employee->is_service_provider ? 'Therapist' : 'Non-layanan' }}</td>
                            <td><span class="role-badge {{ $employee->active ? 'ui-status-success' : 'ui-status-neutral' }} badge badge-soft">{{ $employee->active ? 'Aktif' : 'Nonaktif' }}</span></td>
                            <td class="align-right text-right tabular-nums"><div class="table-actions flex flex-wrap items-center justify-end gap-2">
                                <a class="access-button action-edit ui-action-edit compact btn btn-sm btn-accent" href="{{ route('access.users.employees.edit', $employee) }}">Edit</a>
                                @can('access.users.manage')
                                    <form method="POST" action="{{ route('access.users.employees.destroy', $employee) }}" onsubmit="return confirm('Hapus karyawan ini? Jika sudah memiliki riwayat jadwal atau penggajian, sistem akan menonaktifkannya agar histori tetap aman.')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="access-button action-delete ui-action-delete compact btn btn-sm btn-error" type="submit">Hapus</button>
                                    </form>
                                @endcan
                            </div></td>
                        </tr>
                    @empty
                    @endforelse
                    @if ($users->isEmpty() && $employeesWithoutAccount->isEmpty())
                        <tr><td colspan="5" class="empty-state py-8 text-center text-sm text-base-content/60">Belum ada karyawan.</td></tr>
                    @endif
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @can('access.users.manage')
        <div class="modal {{ $errors->any() ? 'open' : '' }} p-4" id="user-modal" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
            <div class="modal-box small w-full max-w-lg max-h-[calc(100dvh-2rem)] overflow-y-auto">
                <div class="modal-head grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3 border-b border-base-300 pb-4 mb-5">
                    <div><h2 id="user-modal-title">Tambah pengguna</h2><p>Pilih Terapis untuk mencatat karyawan tanpa akses login.</p></div>
                    <button type="button" class="user-modal-close material-symbols-outlined btn btn-sm btn-info" aria-label="Tutup">close</button>
                </div>
                <form method="POST" action="{{ route('access.users.store') }}" class="access-form" id="employee-user-form">
                    @csrf
                    <label id="identity-label">Username<input class="input w-full min-w-0" name="identity" value="{{ old('identity') }}" required maxlength="100" autofocus autocomplete="username" placeholder="Contoh: kasir.selesa"></label>
                    <label>Peran<select class="select w-full min-w-0" name="role_id" id="user-role" required><option value="">Pilih peran</option><option value="therapist" @selected(old('role_id') === 'therapist')>Terapis</option>@foreach ($roles as $role)<option value="{{ $role->id }}" @selected((string) old('role_id') === (string) $role->id)>{{ $role->display_name ?: $role->name }}</option>@endforeach</select></label>
                    <label id="specialty-field" hidden>Spesialisasi<input class="input w-full min-w-0" name="specialty" value="{{ old('specialty') }}" maxlength="150" placeholder="Contoh: Hair therapist"></label>
                    <div class="login-fields grid gap-4" id="account-fields">
                        <label>Kata sandi<input class="input w-full min-w-0" type="password" name="password" minlength="8" autocomplete="new-password"></label>
                        <label>Konfirmasi kata sandi<input class="input w-full min-w-0" type="password" name="password_confirmation" minlength="8" autocomplete="new-password"></label>
                    </div>
                    <footer>
                        <button type="button" class="access-button secondary user-modal-close btn btn-secondary">Batal</button>
                        <button class="access-button primary btn btn-primary" type="submit">Simpan</button>
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
        const roleSelect = userModal?.querySelector('#user-role');
        const accountFields = userModal?.querySelector('#account-fields');
        const specialtyField = userModal?.querySelector('#specialty-field');
        const identityLabel = userModal?.querySelector('#identity-label');
        const syncForm = () => {
            const isTherapist = roleSelect?.value === 'therapist';
            accountFields.hidden = isTherapist;
            specialtyField.hidden = ! isTherapist;
            specialtyField.querySelector('input').required = isTherapist;
            accountFields.querySelectorAll('input').forEach((field) => field.required = ! isTherapist);
            identityLabel.firstChild.textContent = isTherapist ? 'Nama terapis' : 'Username';
            identityLabel.querySelector('input').placeholder = isTherapist ? 'Contoh: Dita' : 'Contoh: kasir.selesa';
        };

        document.getElementById('open-user-modal')?.addEventListener('click', () => userModal?.classList.add('open'));
        document.querySelectorAll('.user-modal-close').forEach((button) => button.addEventListener('click', closeUserModal));
        userModal?.addEventListener('click', (event) => {
            if (event.target === userModal) closeUserModal();
        });
        roleSelect?.addEventListener('change', syncForm);
        syncForm();
    </script>
@endpush
