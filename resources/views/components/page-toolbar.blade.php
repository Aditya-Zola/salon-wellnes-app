@props(['panel' => true])

<div @class(['page-toolbar flex min-w-0 flex-col gap-3 xl:flex-row xl:flex-wrap xl:items-end xl:justify-between', 'rounded-box border border-base-300 bg-base-100 p-4 shadow-sm' => $panel && (isset($filters) || $slot->isNotEmpty())])>
    @if ($slot->isNotEmpty())
        <div class="min-w-0 shrink-0">{{ $slot }}</div>
    @endif
    @isset($filters)
        <div class="page-filters flex min-w-0 flex-1 flex-wrap items-end gap-3 [&>.input]:min-w-0 [&>.input]:flex-1 [&>.input]:basis-60 [&>.select]:min-w-0 [&>.select]:flex-1 [&>.select]:basis-44 [&>label]:min-w-0 [&>label]:flex-1 [&>label]:basis-44">
            {{ $filters }}
        </div>
    @endisset
    @isset($actions)
        <div class="page-actions flex min-w-0 max-w-full flex-wrap items-end justify-end gap-2 xl:ml-auto [&>.btn]:max-sm:flex-1">
            {{ $actions }}
        </div>
    @endisset
</div>
