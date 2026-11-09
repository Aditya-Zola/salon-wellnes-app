<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 3mm 1mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #202020; font-family: Helvetica, DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.3; }
        .receipt { width: {{ $paperWidth - 2 }}mm; max-width: 100%; margin: 0 auto; }
        .header { padding-top: 4mm; text-align: center; }
        .brand-logo { display: block; width: 36mm; height: auto; margin: 0 auto 1.5mm; }
        .address { margin: 0 auto 1.5mm; color: #514a44; font-size: 8px; }
        .dash { margin: 2mm 0; border-top: 1px dashed #777; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: .45mm 0; vertical-align: top; }
        .meta td:first-child { width: 17mm; white-space: nowrap; }
        .meta td:nth-child(2) { width: 3mm; text-align: center; }
        .meta td:last-child { padding-left: .7mm; overflow-wrap: break-word; }
        .caption { margin: 0; font-weight: normal; font-size: 9px; text-align: center; }
        .items { table-layout: fixed; }
        .items td { padding: 0; vertical-align: top; }
        .items .name td { padding-top: .75mm; }
        .items .detail td { padding: .2mm 0 .75mm 1.5mm; }
        .items .detail td:first-child { width: 36mm; }
        .items .detail td:last-child { width: 20mm; padding-left: 2mm; text-align: right; white-space: nowrap; }
        .items .stock td { padding: 0 0 .75mm 1.5mm; color: #514a44; font-size: 8px; }
        .totals { table-layout: fixed; }
        .totals tr { border-bottom: 1px dashed #777; }
        .totals td { padding: 1.1mm 0; }
        .totals td:first-child { width: 33mm; text-align: right; }
        .totals td:nth-child(2) { width: 3mm; text-align: center; }
        .totals td:last-child { width: 20mm; text-align: right; white-space: nowrap; }
        .totals .grand td { font-size: 10px; font-weight: bold; }
        .reason, .reference { margin: 2mm 0; font-size: 8px; overflow-wrap: break-word; }
        .footer { margin-top: 2.5mm; text-align: center; }
        .footer strong { display: block; padding-bottom: 1.5mm; margin-bottom: 1.5mm; border-bottom: 1px dashed #777; font-size: 9px; }
        .footer p { margin: .6mm 0; padding-left: 1.5mm; text-align: left; }
    </style>
</head>
<body>
    <main class="receipt">
        <header class="header">
            @if ($logoDataUri)<img class="brand-logo" src="{{ $logoDataUri }}" alt="Selesa Salon">@endif
            <p class="address">{{ $salon['address'] }}</p>
        </header>
        <div class="dash"></div>
        <table class="meta">
            <tr><td>Pelanggan</td><td>:</td><td>{{ $return->customer_name }}</td></tr>
            <tr><td>Tanggal retur</td><td>:</td><td>{{ \Carbon\CarbonImmutable::parse($return->returned_at)->translatedFormat('d M Y, H:i') }}</td></tr>
            <tr><td>Karyawan</td><td>:</td><td>{{ $return->created_by_name ?: 'Admin Selesa' }}</td></tr>
            <tr><td>Invoice asal</td><td>:</td><td>{{ preg_replace('/[-_\s]+/', '', $return->transaction_number) }}</td></tr>
        </table>
        <div class="dash"></div>
        <p class="caption">STRUK RETUR &middot; {{ $return->number }}</p>
        <div class="dash"></div>
        <table class="items">
            @foreach ($items as $item)
                <tr class="name"><td colspan="2">{{ $item->name }}</td></tr>
                <tr class="detail">
                    <td>@if($item->item_type === 'product'){{ rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.') }} x Rp{{ number_format($item->unit_price, 0, ',', '.') }}@else{{ $item->amount < $item->original_amount ? 'Refund sebagian' : 'Refund penuh' }}@endif</td>
                    <td>Rp{{ number_format($item->amount, 0, ',', '.') }}</td>
                </tr>
                @if($item->item_type === 'product')<tr class="stock"><td colspan="2">{{ $item->restocked ? 'Kembali ke stok' : 'Tidak kembali ke stok' }}</td></tr>@endif
            @endforeach
        </table>
        <div class="dash"></div>
        <table class="totals">
            <tr class="grand"><td>Total refund</td><td>:</td><td>Rp{{ number_format($return->total_amount, 0, ',', '.') }}</td></tr>
            <tr><td>{{ $return->payment_method_name }}</td><td>:</td><td>Rp{{ number_format($return->total_amount, 0, ',', '.') }}</td></tr>
        </table>
        @if($return->reference_number)<p class="reference">Referensi: {{ $return->reference_number }}</p>@endif
        <p class="reason">Alasan retur: {{ $return->reason }}</p>
        <footer class="footer"><strong>TERIMA KASIH</strong><p>{{ $salon['address'] }}</p>@if($salon['whatsapp'])<p>WhatsApp : {{ $salon['whatsapp'] }}</p>@endif</footer>
    </main>
</body>
</html>
