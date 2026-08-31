<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 3mm 4mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #202020; font-family: DejaVu Sans Mono, monospace; font-size: 8px; line-height: 1.2; }
        .receipt { width: 50mm; margin: 0 auto; }
        .header { padding-bottom: 3px; border-bottom: 1px dashed #4d4d4d; text-align: center; }
        .brand-logo { display: block; width: 24mm; height: auto; margin: 0 auto 3px; }
        .receipt-number { margin: 0; font-size: 8px; font-weight: bold; }
        .section { padding: 4px 0; border-bottom: 1px dashed #4d4d4d; }
        .meta, .totals { width: 100%; border-collapse: collapse; }
        .meta td { padding: 1px 0; vertical-align: top; }
        .meta td:first-child { width: 18mm; text-align: right; }
        .meta td:nth-child(2), .totals td:nth-child(2) { width: 2.5mm; text-align: center; }
        .meta td:last-child { font-weight: bold; text-align: right; }
        .caption { margin: 0 0 3px; font-size: 8px; font-weight: bold; text-align: center; }
        .item { display: table; width: 100%; padding: 2px 0; }
        .item-name, .item-amount { display: table-cell; vertical-align: top; }
        .item-name { width: 68%; font-weight: bold; }
        .item-amount { width: 32%; font-weight: bold; text-align: right; white-space: nowrap; }
        .item-detail { margin: 1px 0 0 1.5mm; color: #585858; font-size: 7px; font-weight: normal; }
        .totals td { padding: 1px 0; }
        .totals td:first-child { text-align: right; }
        .totals td:last-child { text-align: right; font-weight: bold; }
        .totals .grand td { padding-top: 3px; border-top: 1px dashed #4d4d4d; font-size: 10px; font-weight: bold; }
        .reason { margin: 0; line-height: 1.35; }
        .footer { margin-top: 5px; color: #595959; font-size: 7px; text-align: center; }
    </style>
</head>
<body>
    <main class="receipt">
        <header class="header">
            @if ($logoDataUri)<img class="brand-logo" src="{{ $logoDataUri }}" alt="Selesa Salon">@endif
            <p class="receipt-number">{{ $return->number }}</p>
        </header>

        <section class="section">
            <table class="meta">
                <tr><td>Invoice awal</td><td>:</td><td>{{ preg_replace('/[-_\s]+/', '', $return->transaction_number) }}</td></tr>
                <tr><td>Tanggal retur</td><td>:</td><td>{{ \Carbon\CarbonImmutable::parse($return->returned_at)->translatedFormat('d M Y, H:i') }}</td></tr>
                <tr><td>Pelanggan</td><td>:</td><td>{{ $return->customer_name }}</td></tr>
                <tr><td>Diproses oleh</td><td>:</td><td>{{ $return->created_by_name ?: 'Admin Selesa' }}</td></tr>
            </table>
        </section>

        <section class="section">
            <p class="caption">STRUK RETUR PRODUK</p>
            @foreach ($items as $item)
                <div class="item">
                    <div class="item-name">
                        {{ $item->product_name }}
                        <p class="item-detail">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.') }} x Rp {{ number_format($item->unit_price, 0, ',', '.') }} &middot; {{ $item->restocked ? 'Kembali ke stok' : 'Tidak kembali ke stok' }}</p>
                    </div>
                    <div class="item-amount">Rp {{ number_format($item->amount, 0, ',', '.') }}</div>
                </div>
            @endforeach
        </section>

        <section class="section">
            <table class="totals">
                <tr class="grand"><td>Total refund</td><td>:</td><td>Rp {{ number_format($return->total_amount, 0, ',', '.') }}</td></tr>
                <tr><td>Metode refund</td><td>:</td><td>{{ $return->payment_method_name }}@if($return->reference_number) &middot; {{ $return->reference_number }}@endif</td></tr>
            </table>
        </section>

        <section class="section">
            <p class="reason"><strong>Alasan retur:</strong> {{ $return->reason }}</p>
        </section>
        <footer class="footer">Simpan struk ini sebagai bukti pengembalian produk dan dana.</footer>
    </main>
</body>
</html>
