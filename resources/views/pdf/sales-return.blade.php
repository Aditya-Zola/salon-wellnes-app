<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 16mm; }
        * { box-sizing: border-box; }
        body { color: #2d2926; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        .header { padding-bottom: 14px; border-bottom: 2px solid #8f3e36; }
        .brand-logo { display: block; width: 124px; height: auto; margin-bottom: 4px; }
        .brand { margin: 0; color: #765039; font-size: 27px; font-weight: bold; }
        .badge { display: inline-block; margin-top: 22px; padding: 5px 8px; background: #f9e9e6; color: #8f3e36; font-size: 8px; font-weight: bold; letter-spacing: 1px; }
        .title { margin: 8px 0 5px; color: #1f1b18; font-size: 17px; }
        .return-number { color: #8f3e36; font-size: 11px; font-weight: bold; }
        .meta { width: 100%; margin: 17px 0 20px; border-collapse: collapse; }
        .meta td { width: 50%; padding: 4px 0; vertical-align: top; }
        .meta span { color: #817871; }
        .meta b { display: block; margin-top: 2px; font-size: 10px; }
        table.items { width: 100%; border-collapse: collapse; }
        .items th { padding: 9px 8px; background: #f3ece9; color: #684236; font-size: 9px; text-align: left; }
        .items th:last-child, .items td:last-child { text-align: right; }
        .items td { padding: 10px 8px; border-bottom: 1px solid #e7e0db; }
        .items small { display: block; margin-top: 3px; color: #847c76; font-size: 8px; }
        .total { width: 48%; margin: 16px 0 0 auto; border-collapse: collapse; }
        .total td { padding: 8px 0; border-top: 1px solid #8f3e36; color: #7d312c; font-size: 12px; font-weight: bold; }
        .total td:last-child { text-align: right; }
        .refund { margin-top: 21px; padding: 12px 14px; background: #f8f5f2; }
        .refund h3 { margin: 0 0 7px; color: #644432; font-size: 10px; }
        .refund p { margin: 5px 0; }
        .refund b { float: right; }
        .reason { margin-top: 18px; padding: 10px 12px; border-left: 3px solid #c78a7d; background: #fff9f7; line-height: 1.5; }
        .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #dfd6cf; color: #817871; font-size: 8px; text-align: center; }
    </style>
</head>
<body>
    <header class="header">
        @if ($logoDataUri)<img class="brand-logo" src="{{ $logoDataUri }}" alt="Selesa Salon">@else<p class="brand">selesa</p>@endif
    </header>

    <span class="badge">BUKTI PENGEMBALIAN DANA</span>
    <h1 class="title">STRUK RETUR PRODUK</h1>
    <p class="return-number">{{ $return->number }}</p>

    <table class="meta">
        <tr>
            <td><span>Invoice awal</span><b>{{ $return->transaction_number }}</b></td>
            <td><span>Tanggal retur</span><b>{{ \Carbon\CarbonImmutable::parse($return->returned_at)->translatedFormat('d F Y, H:i') }}</b></td>
        </tr>
        <tr>
            <td><span>Pelanggan</span><b>{{ $return->customer_name }}</b></td>
            <td><span>Diproses oleh</span><b>{{ $return->created_by_name ?: 'Admin Selesa' }}</b></td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th>Produk</th><th>Qty retur</th><th>Harga</th><th>Refund</th></tr></thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td><strong>{{ $item->product_name }}</strong><small>{{ $item->restocked ? 'Dikembalikan ke stok' : 'Tidak kembali ke stok' }}</small></td>
                <td>{{ rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.') }}</td>
                <td>Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                <td>Rp {{ number_format($item->amount, 0, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="total"><tr><td>Total dikembalikan</td><td>Rp {{ number_format($return->total_amount, 0, ',', '.') }}</td></tr></table>

    <section class="refund">
        <h3>PENGEMBALIAN DANA</h3>
        <p>{{ $return->payment_method_name }}@if($return->reference_number) · {{ $return->reference_number }}@endif <b>Rp {{ number_format($return->total_amount, 0, ',', '.') }}</b></p>
    </section>
    <div class="reason"><strong>Alasan retur</strong><br>{{ $return->reason }}</div>

    <footer class="footer">Simpan struk ini sebagai bukti pengembalian produk dan dana.</footer>
</body>
</html>
