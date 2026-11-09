@extends('layouts.internal')

@section('title', 'Atur Hak Akses - Selesa Salon')
@section('heading', 'Atur Hak Akses')
@section('subtitle', 'Centang menu dan tindakan yang dapat digunakan oleh peran ini.')

@section('header-action')
    <a class="access-button secondary btn btn-secondary" href="{{ route('access.roles.index') }}"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Kembali ke daftar</a>
@endsection

@section('content')
    @php
        $selectedPermissions = collect(old('permissions', $role->permissions->pluck('id')->all()))
            ->map(fn ($id) => (int) $id)
            ->all();
        $canManage = auth()->user()->can('access.roles.manage');
        $isProtectedSuperAdmin = $role->name === 'super-admin';
    @endphp

    <form method="POST" action="{{ route('access.roles.update', $role) }}" class="permission-editor grid gap-5">
        @csrf
        @method('PUT')

        <section class="access-card role-name-card card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
            <label>
                Nama peran
                <input class="input w-full min-w-0" name="display_name" value="{{ old('display_name', $role->display_name ?: $role->name) }}" required maxlength="80" @readonly($role->is_system || ! $canManage)>
            </label>
            <div class="role-meta flex flex-wrap gap-2 text-xs text-base-content/60">
                <span>{{ $role->is_system ? 'Peran bawaan' : 'Peran custom' }}</span>
                <span>{{ $selectedPermissions ? count($selectedPermissions) : 0 }} hak akses terpilih</span>
            </div>
        </section>

        @if ($isProtectedSuperAdmin)
            <div class="alert alert-info">Super Admin selalu memiliki seluruh hak akses dan tidak dapat dibatasi.</div>
        @endif

        <div class="permission-toolbar flex flex-wrap items-center justify-between gap-3">
            <div>
                <strong>Hak akses per menu</strong>
            </div>
            @if ($canManage && ! $isProtectedSuperAdmin)
                <div>
                    <button type="button" class="access-button secondary compact btn btn-sm btn-secondary" id="select-all"><span class="material-symbols-outlined" aria-hidden="true">done_all</span> Pilih semua</button>
                    <button type="button" class="access-button secondary compact btn btn-sm btn-secondary" id="clear-all">Kosongkan pilihan</button>
                </div>
            @endif
        </div>

        <div class="permission-groups grid items-start gap-4 lg:grid-cols-2">
            @foreach ($permissionGroups as $group => $permissions)
                <fieldset class="permission-group fieldset rounded-box border border-base-300 bg-base-100 p-5">
                    <legend>
                        <span>{{ $group }}</span>
                        <small>{{ $permissions->count() }} hak akses</small>
                    </legend>
                    <div class="permission-list grid gap-3">
                        @foreach ($permissions as $permission)
                            <label class="permission-item flex items-center gap-3 py-2">
                                <input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                    @checked($permission->name === 'dashboard.view' || $isProtectedSuperAdmin || in_array($permission->id, $selectedPermissions, true))
                                    @disabled(! $canManage || $isProtectedSuperAdmin || $permission->name === 'dashboard.view')>
                                <span>
                                    <strong>{{ $permission->label ?: $permission->name }}</strong>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        </div>

        @if ($canManage)
            <div class="sticky-actions sticky bottom-0 z-10 flex justify-end gap-3 rounded-box border border-base-300 bg-base-100 p-4">
                <a class="access-button secondary btn btn-secondary" href="{{ route('access.roles.index') }}">Batal</a>
                <button class="access-button primary btn btn-primary" type="submit"><span class="material-symbols-outlined" aria-hidden="true">save</span> Simpan hak akses</button>
            </div>
        @endif
    </form>
@endsection

@push('scripts')
    <script>
        const permissionInputs = document.querySelectorAll('.permission-item input:not(:disabled)');
        document.getElementById('select-all')?.addEventListener('click', () => permissionInputs.forEach(input => input.checked = true));
        document.getElementById('clear-all')?.addEventListener('click', () => permissionInputs.forEach(input => input.checked = false));
    </script>
@endpush
