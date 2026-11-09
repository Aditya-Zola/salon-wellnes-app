@extends('layouts.internal')

@section('title', 'Edit Karyawan - Selesa Salon')
@section('heading', 'Edit Karyawan')
@section('subtitle', 'Karyawan ini tidak memiliki akses login dan tetap tersedia untuk jadwal serta penggajian.')

@section('header-action')
    <a class="access-button secondary btn btn-secondary" href="{{ route('access.users.index') }}">Kembali ke daftar</a>
@endsection

@section('content')
    @php($canManage = auth()->user()->can('access.users.manage'))
    <section class="access-card edit-user-card card bg-base-100 border border-base-300 p-5 gap-4 shadow-sm">
        <div class="edit-user-heading flex items-center gap-4"><i class="grid size-12 shrink-0 place-items-center rounded-full bg-primary/10 text-base font-semibold not-italic text-primary">{{ strtoupper(substr($employee->name, 0, 2)) }}</i><div><h2>{{ $employee->name }}</h2><p>{{ $employee->code }} · Tanpa akses login</p></div></div>
        <form method="POST" action="{{ route('access.users.employees.update', $employee) }}" class="access-form two-form-columns grid gap-4 md:grid-cols-2">
            @csrf
            @method('PUT')
            <label>Nama karyawan<input class="input w-full min-w-0" name="name" value="{{ old('name', $employee->name) }}" required maxlength="100" @disabled(! $canManage)></label>
            <label>Posisi<input class="input w-full min-w-0" name="position" value="{{ old('position', $employee->position) }}" maxlength="100" @disabled(! $canManage)></label>
            <label>Spesialisasi<input class="input w-full min-w-0" name="specialty" value="{{ old('specialty', $employee->specialty) }}" maxlength="150" @disabled(! $canManage)></label>
            <label class="access-checkbox flex items-center gap-3 py-2"><input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="is_service_provider" value="1" @checked(old('is_service_provider', $employee->is_service_provider)) @disabled(! $canManage)><span>Karyawan ini menangani treatment</span></label>
            <label class="access-checkbox flex items-center gap-3 py-2"><input class="checkbox checkbox-primary checkbox-sm" type="checkbox" name="active" value="1" @checked(old('active', $employee->active)) @disabled(! $canManage)><span>Karyawan aktif</span></label>
            @if ($canManage)<div class="form-actions col-span-full flex flex-wrap justify-end gap-3 pt-4"><a class="access-button secondary btn btn-secondary" href="{{ route('access.users.index') }}">Batal</a><button class="access-button primary btn btn-primary" type="submit">Simpan perubahan</button></div>@endif
        </form>
    </section>
@endsection
