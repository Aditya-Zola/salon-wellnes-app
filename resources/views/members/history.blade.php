@extends('layouts.internal')

@section('title', 'Riwayat Treatment - Selesa Salon')
@section('heading', 'Riwayat Treatment')
@section('subtitle', 'Riwayat layanan member')

@section('header-action')
    <a class="btn btn-secondary" href="{{ route('dashboard') }}#membership"><span class="material-symbols-outlined" aria-hidden="true">arrow_back</span> Membership</a>
@endsection

@section('content')
    <section class="card gap-4 border border-base-300 bg-base-100 p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0">
                <h2 class="break-words">{{ $member->name }}</h2>
                <p class="mt-1 text-sm text-base-content/60">{{ $member->phone ?: '-' }}</p>
            </div>
            <span class="badge badge-primary badge-outline h-auto py-2">{{ $history->total() }} layanan{{ $search !== '' ? ' ditemukan' : ' tercatat' }}</span>
        </div>
        <form class="flex flex-wrap items-end gap-3 border-t border-base-300 pt-4" method="GET" action="{{ route('members.history', $member->id) }}">
            <label class="min-w-0 flex-1 sm:max-w-md">Cari riwayat
                <input class="input w-full" type="search" name="search" value="{{ $search }}" maxlength="100" placeholder="Treatment atau terapis">
            </label>
            <button class="btn btn-primary" type="submit"><span class="material-symbols-outlined" aria-hidden="true">search</span> Cari</button>
            @if ($search !== '')<a class="btn btn-info" href="{{ route('members.history', $member->id) }}">Reset</a>@endif
        </form>
    </section>

    <section class="card gap-4 border border-base-300 bg-base-100 p-5 shadow-sm">
        <div class="overflow-x-auto">
            <table class="table table-zebra w-full">
                <thead class="bg-primary/10 text-primary"><tr><th class="w-12">No.</th><th>Treatment</th><th>Tanggal reservasi</th><th>Terapis</th></tr></thead>
                <tbody>
                    @forelse ($history as $item)
                        <tr>
                            <td class="text-base-content/60">{{ $history->firstItem() + $loop->index }}</td>
                            <td class="min-w-40 font-semibold">{{ $item->name }}</td>
                            <td class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($item->reservation_date)->locale('id')->translatedFormat('d M Y') }}</td>
                            <td class="min-w-32">{{ $item->therapist_name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-12 text-center text-base-content/60">{{ $search !== '' ? 'Tidak ada riwayat yang cocok.' : 'Belum ada riwayat treatment.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 pt-4 text-sm">
            <span class="text-base-content/60">{{ $history->firstItem() ?? 0 }}–{{ $history->lastItem() ?? 0 }} dari {{ $history->total() }} layanan</span>
            @if ($history->hasPages())
                <nav class="flex flex-wrap items-center gap-2" aria-label="Halaman riwayat">
                    @if ($history->onFirstPage())<span class="btn btn-sm btn-disabled" aria-disabled="true">Sebelumnya</span>@else<a class="btn btn-sm btn-secondary" rel="prev" href="{{ $history->previousPageUrl() }}">Sebelumnya</a>@endif
                    <span class="px-2">{{ $history->currentPage() }} / {{ $history->lastPage() }}</span>
                    @if ($history->hasMorePages())<a class="btn btn-sm btn-primary" rel="next" href="{{ $history->nextPageUrl() }}">Berikutnya</a>@else<span class="btn btn-sm btn-disabled" aria-disabled="true">Berikutnya</span>@endif
                </nav>
            @endif
        </div>
    </section>
@endsection
