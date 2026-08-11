@extends('layouts.internal')

@section('title', 'Edit Pengguna - Selesa Salon')
@section('heading', 'Edit Pengguna')
@section('subtitle', 'Perbarui profil, kata sandi, atau peran pengguna.')

@section('header-action')
    <a class="access-button secondary" href="{{ route('access.users.index') }}">Kembali ke daftar</a>
@endsection

@section('content')
    @php
        $canManage = auth()->user()->can('access.users.manage');
        $directPermissionIds = collect(old('permissions', $user->getDirectPermissions()->pluck('id')->all()))
            ->map(fn ($id) => (int) $id)
            ->all();
        $rolePermissionIds = $user->getPermissionsViaRoles()->pluck('id')->map(fn ($id) => (int) $id)->all();
    @endphp
    <section class="access-card edit-user-card">
        <div class="edit-user-heading">
            <i>{{ strtoupper(substr($user->name, 0, 2)) }}</i>
            <div><h2>{{ $user->name }}</h2><p>{{ $user->email }}</p></div>
        </div>

        <form method="POST" action="{{ route('access.users.update', $user) }}" class="access-form two-form-columns">
            @csrf
            @method('PUT')
            <label>Nama<input name="name" value="{{ old('name', $user->name) }}" required maxlength="100" @disabled(! $canManage)></label>
            <label>Email<input type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="150" @disabled(! $canManage)></label>
            <label>
                Peran
                <select name="role_id" required @disabled(! $canManage)>
                    @foreach ($roles as $role)
                        <option value="{{ $role->id }}" @selected((string) old('role_id', $user->roles->first()?->id) === (string) $role->id)>{{ $role->display_name ?: $role->name }}</option>
                    @endforeach
                </select>
            </label>
            <div></div>
            <label>Kata sandi baru <small>(opsional)</small><input type="password" name="password" minlength="8" @disabled(! $canManage)></label>
            <label>Konfirmasi kata sandi<input type="password" name="password_confirmation" minlength="8" @disabled(! $canManage)></label>

            <section class="user-permission-editor" style="grid-column: 1 / -1;">
                <div class="permission-toolbar">
                    <div>
                        <strong>Akses tambahan per pengguna</strong>
                        <small>Peran menjadi akses dasar. Centang aksi tambahan khusus untuk akun ini tanpa mengubah pengguna lain.</small>
                    </div>
                </div>

                @if ($user->isSuperAdmin())
                    <div class="alert alert-info">Super Admin selalu memiliki seluruh hak akses melalui perannya.</div>
                @elseif ($permissionGroups->isEmpty())
                    <div class="alert alert-info">Tidak ada hak akses yang dapat dikelola oleh akun Anda.</div>
                @else
                    <div class="permission-groups">
                        @foreach ($permissionGroups as $group => $permissions)
                            <fieldset class="permission-group">
                                <legend><span>{{ $group }}</span><small>{{ $permissions->count() }} aksi</small></legend>
                                <div class="permission-list">
                                    @foreach ($permissions as $permission)
                                        @php($inheritedFromRole = in_array($permission->id, $rolePermissionIds, true))
                                        <label class="permission-item {{ $inheritedFromRole ? 'is-inherited' : '' }}">
                                            <input type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                                @checked($inheritedFromRole || in_array($permission->id, $directPermissionIds, true))
                                                @disabled(! $canManage || $inheritedFromRole)>
                                            <span>
                                                <strong>{{ $permission->label ?: $permission->name }}</strong>
                                                <small>{{ $permission->name }}{{ $inheritedFromRole ? ' · dari peran' : ' · akses pribadi' }}</small>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($canManage)
                <div class="form-actions">
                    <a class="access-button secondary" href="{{ route('access.users.index') }}">Batal</a>
                    <button class="access-button primary" type="submit">Simpan perubahan</button>
                </div>
            @endif
        </form>
    </section>
@endsection
