@extends('layouts.internal')

@section('title', 'Edit Pengguna - Selesa Salon')
@section('heading', 'Edit Pengguna')
@section('subtitle', 'Perbarui profil, kata sandi, atau peran pengguna.')

@section('header-action')
    <a class="access-button secondary" href="{{ route('access.users.index') }}">Kembali ke daftar</a>
@endsection

@section('content')
    @php($canManage = auth()->user()->can('access.users.manage'))
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
            @if ($canManage)
                <div class="form-actions">
                    <a class="access-button secondary" href="{{ route('access.users.index') }}">Batal</a>
                    <button class="access-button primary" type="submit">Simpan perubahan</button>
                </div>
            @endif
        </form>
    </section>
@endsection
