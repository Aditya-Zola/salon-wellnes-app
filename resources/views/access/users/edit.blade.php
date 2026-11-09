@extends('layouts.internal')

@section('title', 'Edit Pengguna & Karyawan - Selesa Salon')
@section('heading', 'Edit Pengguna & Karyawan')
@section('subtitle', 'Perbarui data karyawan, akses login, dan hak akses tambahan.')

@section('header-action')
    <a class="access-button secondary btn btn-secondary" href="{{ route('access.users.index') }}">Kembali ke daftar</a>
@endsection

@section('content')
    @php
        $canManage = auth()->user()->can('access.users.manage');
        $employee = $user->employee;
        $directPermissionIds = collect(old('permissions', $user->getDirectPermissions()->pluck('id')->all()))->map(fn ($id) => (int) $id)->all();
        $rolePermissionIds = $user->getPermissionsViaRoles()->pluck('id')->map(fn ($id) => (int) $id)->all();
    @endphp
    <section class="access-card edit-user-card card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
        <div class="edit-user-heading flex items-center gap-4">
            <i class="grid size-12 shrink-0 place-items-center rounded-full bg-primary/10 text-base font-semibold not-italic text-primary">{{ strtoupper(substr($user->name, 0, 2)) }}</i>
            <div><h2>{{ $user->name }}</h2><p>&#64;{{ $user->username }} · {{ $employee?->position ?: 'Pengguna sistem' }}</p></div>
        </div>

        <form method="POST" action="{{ route('access.users.update', $user) }}" class="access-form two-form-columns grid gap-4 md:grid-cols-2">
            @csrf
            @method('PUT')
            <label>Nama karyawan<input class="input w-full min-w-0" name="name" value="{{ old('name', $user->name) }}" required maxlength="100" @disabled(! $canManage)></label>
            <label>Username<input class="input w-full min-w-0" name="username" value="{{ old('username', $user->username) }}" required maxlength="40" @disabled(! $canManage)></label>
            <label>Posisi<input class="input w-full min-w-0" name="position" value="{{ old('position', $employee?->position) }}" maxlength="100" @disabled(! $canManage)></label>
            <label>Spesialisasi<input class="input w-full min-w-0" name="specialty" value="{{ old('specialty', $employee?->specialty) }}" maxlength="150" @disabled(! $canManage)></label>
            <label>Email (opsional)<input class="input w-full min-w-0" type="email" name="email" value="{{ old('email', $user->email) }}" maxlength="150" @disabled(! $canManage)></label>
            <label>Peran<select class="select w-full min-w-0" name="role_id" required @disabled(! $canManage)>@foreach ($roles as $role)<option value="{{ $role->id }}" @selected((string) old('role_id', $user->roles->first()?->id) === (string) $role->id)>{{ $role->display_name ?: $role->name }}</option>@endforeach</select></label>
            <label class="access-checkbox flex items-center gap-3 py-2"><input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="is_service_provider" value="1" @checked(old('is_service_provider', $employee?->is_service_provider)) @disabled(! $canManage)><span>Karyawan ini menangani treatment</span></label>
            <label class="access-checkbox flex items-center gap-3 py-2"><input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="active" value="1" @checked(old('active', $employee?->active ?? true)) @disabled(! $canManage)><span>Karyawan aktif</span></label>
            <label>Kata sandi baru (opsional)<input class="input w-full min-w-0" type="password" name="password" minlength="8" @disabled(! $canManage)></label>
            <label>Konfirmasi kata sandi<input class="input w-full min-w-0" type="password" name="password_confirmation" minlength="8" @disabled(! $canManage)></label>

            <section class="user-permission-editor col-span-full space-y-4">
                <div class="permission-toolbar flex flex-wrap items-center justify-between gap-3"><div class="grid gap-1"><strong>Akses tambahan per pengguna</strong><small>Pilihan tambahan hanya berlaku untuk akun ini.</small></div></div>
                @if ($user->isSuperAdmin())
                    <div class="alert alert-info">Super Admin selalu memiliki seluruh hak akses melalui perannya.</div>
                @elseif ($permissionGroups->isEmpty())
                    <div class="alert alert-info">Tidak ada hak akses yang dapat dikelola oleh akun Anda.</div>
                @else
                    <div class="permission-groups grid items-start gap-4 lg:grid-cols-2">
                        @foreach ($permissionGroups as $group => $permissions)
                            <fieldset class="permission-group fieldset rounded-box border border-base-300 bg-base-100 p-5"><legend><span>{{ $group }}</span><small>{{ $permissions->count() }} aksi</small></legend><div class="permission-list grid gap-3">
                                @foreach ($permissions as $permission)
                                    @php($inheritedFromRole = in_array($permission->id, $rolePermissionIds, true))
                                    <label class="permission-item {{ $inheritedFromRole ? 'is-inherited' : '' }} flex items-center gap-3 py-2"><input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked($inheritedFromRole || in_array($permission->id, $directPermissionIds, true)) @disabled(! $canManage || $inheritedFromRole)><span><strong>{{ $permission->label ?: $permission->name }}</strong><small>{{ $inheritedFromRole ? 'Dari peran' : 'Akses pribadi' }}</small></span></label>
                                @endforeach
                            </div></fieldset>
                        @endforeach
                    </div>
                @endif
            </section>
            @if ($canManage)<div class="form-actions col-span-full flex flex-wrap justify-end gap-3 pt-4"><a class="access-button secondary btn btn-secondary" href="{{ route('access.users.index') }}">Batal</a><button class="access-button primary btn btn-primary" type="submit">Simpan perubahan</button></div>@endif
        </form>
    </section>
@endsection
