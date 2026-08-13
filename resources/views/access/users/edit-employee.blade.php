@extends('layouts.internal')

@section('title', 'Edit Karyawan - Selesa Salon')
@section('heading', 'Edit Karyawan')
@section('subtitle', 'Karyawan ini tidak memiliki akses login dan tetap tersedia untuk jadwal serta penggajian.')

@section('header-action')
    <a class="access-button secondary" href="{{ route('access.users.index') }}">Kembali ke daftar</a>
@endsection

@section('content')
    @php($canManage = auth()->user()->can('access.users.manage'))
    <section class="access-card edit-user-card">
        <div class="edit-user-heading"><i>{{ strtoupper(substr($employee->name, 0, 2)) }}</i><div><h2>{{ $employee->name }}</h2><p>{{ $employee->code }} · Tanpa akses login</p></div></div>
        <form method="POST" action="{{ route('access.users.employees.update', $employee) }}" class="access-form two-form-columns">
            @csrf
            @method('PUT')
            <label>Nama karyawan<input name="name" value="{{ old('name', $employee->name) }}" required maxlength="100" @disabled(! $canManage)></label>
            <label>Posisi<input name="position" value="{{ old('position', $employee->position) }}" maxlength="100" @disabled(! $canManage)></label>
            <label>Spesialisasi<input name="specialty" value="{{ old('specialty', $employee->specialty) }}" maxlength="150" @disabled(! $canManage)></label>
            <label class="access-checkbox"><input type="checkbox" name="is_service_provider" value="1" @checked(old('is_service_provider', $employee->is_service_provider)) @disabled(! $canManage)><span>Karyawan ini menangani treatment</span></label>
            <label class="access-checkbox"><input type="checkbox" name="active" value="1" @checked(old('active', $employee->active)) @disabled(! $canManage)><span>Karyawan aktif</span></label>
            @if ($canManage)<div class="form-actions"><a class="access-button secondary" href="{{ route('access.users.index') }}">Batal</a><button class="access-button primary" type="submit">Simpan perubahan</button></div>@endif
        </form>
    </section>
@endsection
