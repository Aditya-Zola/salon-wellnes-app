@extends('layouts.internal')

@section('title', 'Peran - Selesa Salon')
@section('heading', 'Peran')
@section('subtitle', 'Buat peran dan tentukan menu serta tindakan yang dapat digunakan.')

@section('header-action')
    <a class="access-button secondary" href="{{ route('dashboard') }}">Kembali ke halaman utama</a>
@endsection

@section('content')
    <div class="access-grid access-grid-sidebar">
        @can('access.roles.manage')
            <section class="access-card form-card">
                <div class="access-card-head">
                    <div>
                        <h2>Tambah peran</h2>
                        <p>Contoh: Terapis, Supervisor, atau Finance.</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('access.roles.store') }}" class="access-form">
                    @csrf
                    <label>
                        Nama peran
                        <input name="display_name" value="{{ old('display_name') }}" placeholder="Contoh: Terapis" required maxlength="80">
                    </label>
                    <button class="access-button primary" type="submit">Buat peran</button>
                </form>
            </section>
        @endcan

        <section class="access-card {{ auth()->user()->cannot('access.roles.manage') ? 'wide-card' : '' }}">
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
@endsection
