<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>@include('pdf.partials.note-style')</style>
</head>
<body>
    <table class="header">
        <tr>
            <td class="salon">
                @if ($logoDataUri)<img class="brand-logo" src="{{ $logoDataUri }}" alt="Selesa Salon">@else<p class="brand">selesa</p>@endif
                <p class="contact">{{ $salon['address'] }}</p>
                @if ($salon['whatsapp'])<p class="contact">WhatsApp: {{ $salon['whatsapp'] }}</p>@endif
                <div class="staff"><p>Petugas: <strong>{{ $return->created_by_name ?: 'Admin Selesa' }}</strong></p></div>
            </td>
            <td>
                <h1>NOTA RETUR</h1>
                <table class="meta">
                    <tr><td class="label">Nomor retur</td><td class="colon">:</td><td><strong>{{ $return->number }}</strong></td></tr>
                    <tr><td class="label">Invoice asal</td><td class="colon">:</td><td>{{ preg_replace('/[-_\s]+/', '', $return->transaction_number) }}</td></tr>
                    <tr><td class="label">Pelanggan</td><td class="colon">:</td><td><strong>{{ $return->customer_name }}</strong></td></tr>
                    <tr><td class="label">Tanggal retur</td><td class="colon">:</td><td>{{ \Carbon\CarbonImmutable::parse($return->returned_at)->translatedFormat('d F Y, H:i') }}</td></tr>
                    <tr><td class="label">Metode refund</td><td class="colon">:</td><td>{{ $return->payment_method_name }}</td></tr>
                    @if ($return->reference_number)<tr><td class="label">Referensi</td><td class="colon">:</td><td>{{ $return->reference_number }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    <p class="notice"><strong>Alasan retur:</strong> {{ $return->reason }}</p>
    <table class="items">
        <thead><tr><th class="center" style="width:5%">No</th><th style="width:37%">Rincian</th><th style="width:13%">Jenis</th><th class="center" style="width:7%">Qty</th><th class="right" style="width:18%">Harga</th><th class="right" style="width:20%">Refund</th></tr></thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td><strong>{{ $item->name }}</strong><small>{{ $item->item_type === 'product' ? ($item->restocked ? 'Kembali ke stok' : 'Tidak kembali ke stok') : ($item->amount < $item->original_amount ? 'Refund sebagian' : 'Refund penuh') }}</small></td>
                    <td>{{ $item->item_type === 'product' ? 'Produk retail' : 'Treatment' }}</td>
                    <td class="center">{{ $item->item_type === 'product' ? rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.') : '-' }}</td>
                    <td class="amount">@if($item->item_type === 'product')Rp {{ number_format($item->unit_price, 0, ',', '.') }}@else - @endif</td>
                    <td class="amount">Rp {{ number_format($item->amount, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="grand"><td colspan="5" class="summary-label">Total refund</td><td class="amount">Rp {{ number_format($return->total_amount, 0, ',', '.') }}</td></tr>
        </tbody>
    </table>
</body>
</html>
