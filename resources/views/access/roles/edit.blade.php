@extends('layouts.internal')

@section('title', 'Atur Hak Akses - Selesa Salon')
@section('heading', 'Atur Hak Akses')
@section('subtitle', 'Centang menu dan tindakan yang dapat digunakan oleh peran ini.')

@section('header-action')
    <a class="access-button secondary" href="{{ route('access.roles.index') }}">Kembali ke daftar</a>
@endsection

@section('content')
    @php
        $selectedPermissions = collect(old('permissions', $role->permissions->pluck('id')->all()))
            ->map(fn ($id) => (int) $id)
            ->all();
        $canManage = auth()->user()->can('access.roles.manage');
        $isProtectedSuperAdmin = $role->name === 'super-admin';
    @endphp

    <form method="POST" action="{{ route('access.roles.update', $role) }}" class="permission-editor">
        @csrf
        @method('PUT')

        <section class="access-card role-name-card">
            <label>
                Nama peran
                <input name="display_name" value="{{ old('display_name', $role->display_name ?: $role->name) }}" required maxlength="80" @readonly($role->is_system || ! $canManage)>
            </label>
            <div class="role-meta">
                <span>{{ $role->is_system ? 'Peran bawaan' : 'Peran custom' }}</span>
                <span>{{ $selectedPermissions ? count($selectedPermissions) : 0 }} hak akses terpilih</span>
            </div>
        </section>

        @if ($isProtectedSuperAdmin)
            <div class="alert alert-info">Super Admin selalu memiliki seluruh hak akses dan tidak dapat dibatasi.</div>
        @endif

        <div class="permission-toolbar">
            <div>
                <strong>Daftar permission</strong>
                <small>Pilih sesuai tanggung jawab peran.</small>
            </div>
            @if ($canManage && ! $isProtectedSuperAdmin)
                <div>
                    <button type="button" class="text-button" id="select-all">Pilih semua</button>
                    <button type="button" class="text-button" id="clear-all">Kosongkan</button>
                </div>
            @endif
        </div>

        <div class="permission-groups">
            @foreach ($permissionGroups as $group => $permissions)
                <fieldset class="permission-group">
                    <legend>
                        <span>{{ $group }}</span>
                        <small>{{ $permissions->count() }} permission</small>
                    </legend>
                    <div class="permission-list">
                        @foreach ($permissions as $permission)
                            <label class="permission-item">
                                <input type="checkbox" name="permissions[]" value="{{ $permission->id }}"
                                    @checked($permission->name === 'dashboard.view' || $isProtectedSuperAdmin || in_array($permission->id, $selectedPermissions, true))
                                    @disabled(! $canManage || $isProtectedSuperAdmin || $permission->name === 'dashboard.view')>
                                <span>
                                    <strong>{{ $permission->label ?: $permission->name }}</strong>
                                    <small>{{ $permission->name }}</small>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
        </div>

        @if ($canManage)
            <div class="sticky-actions">
                <a class="access-button secondary" href="{{ route('access.roles.index') }}">Batal</a>
                <button class="access-button primary" type="submit">Simpan hak akses</button>
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
