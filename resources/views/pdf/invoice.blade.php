<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 16mm; }
        * { box-sizing: border-box; }
        body { color: #2d2926; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        .header { padding-bottom: 14px; border-bottom: 2px solid #765039; }
        .brand-logo { display: block; width: 170px; height: auto; margin: 0 0 8px; }
        .brand { margin: 0; color: #765039; font-size: 27px; font-weight: bold; letter-spacing: 1px; }
        .subtitle { display: none; }
        .title { margin: 22px 0 5px; color: #1f1b18; font-size: 17px; }
        .invoice-number { color: #765039; font-size: 11px; font-weight: bold; }
        .meta { width: 100%; margin: 17px 0 20px; border-collapse: collapse; }
        .meta td { width: 50%; padding: 3px 0; vertical-align: top; }
        .meta span { color: #817871; }
        .meta b { display: block; margin-top: 2px; font-size: 10px; }
        table.items { width: 100%; border-collapse: collapse; }
        .items th { padding: 9px 8px; background: #f1ece8; color: #644432; font-size: 9px; text-align: left; }
        .items th:last-child, .items td:last-child { text-align: right; }
        .items td { padding: 10px 8px; border-bottom: 1px solid #e7e0db; vertical-align: top; }
        .items small { display: block; margin-top: 3px; color: #847c76; font-size: 8px; }
        .right { text-align: right; }
        .summary { width: 45%; margin: 16px 0 0 auto; border-collapse: collapse; }
        .summary td { padding: 5px 0; }
        .summary td:last-child { text-align: right; font-weight: bold; }
        .summary .grand td { padding-top: 9px; border-top: 1px solid #765039; color: #5d3824; font-size: 12px; }
        .payments { margin-top: 21px; padding: 12px 14px; background: #f8f5f2; }
        .payments h3 { margin: 0 0 7px; color: #644432; font-size: 10px; }
        .payments p { margin: 4px 0; }
        .payments b { float: right; }
        .footer { margin-top: 32px; padding-top: 12px; border-top: 1px solid #dfd6cf; color: #817871; font-size: 8px; text-align: center; }
    </style>
</head>
<body>
    <header class="header">
        @if ($logoDataUri)
            <img class="brand-logo" src="{{ $logoDataUri }}" alt="Selesa Salon">
        @else
            <p class="brand">selesa</p>
        @endif
        <p class="subtitle">SALON · SPA · WELLNESS · NAIL · EYELASH</p>
    </header>

    <h1 class="title">NOTA PEMBAYARAN</h1>
    <p class="invoice-number">{{ preg_replace('/[-_\s]+/', '', $invoice->number) }}</p>

    <table class="meta">
        <tr>
            <td><span>Pelanggan</span><b>{{ $invoice->customer_name }}</b></td>
            <td><span>Tanggal transaksi</span><b>{{ \Carbon\CarbonImmutable::parse($invoice->transacted_at)->translatedFormat('d F Y, H:i') }}</b></td>
        </tr>
        <tr>
            <td><span>Kasir</span><b>{{ $invoice->cashier_name ?: 'Kasir Selesa' }}</b></td>
            <td><span>Terapis</span><b>{{ $therapists->isNotEmpty() ? $therapists->join(', ') : '-' }}</b></td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th>Rincian</th><th>Qty</th><th>Harga</th><th>Total</th></tr></thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td><strong>{{ $item->name }}</strong><small>{{ $item->item_type === 'product' ? 'Produk retail' : 'Treatment' }}@if((float) $item->returned_quantity > 0) · Diretur {{ rtrim(rtrim(number_format((float) $item->returned_quantity, 4, '.', ''), '0'), '.') }}@endif</small></td>
                <td>{{ rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.') }}</td>
                <td class="right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                <td>Rp {{ number_format($item->total_amount, 0, ',', '.') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="summary">
        <tr><td>Subtotal</td><td>Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td></tr>
        @if ($invoice->discount_amount > 0)<tr><td>Diskon</td><td>-Rp {{ number_format($invoice->discount_amount, 0, ',', '.') }}</td></tr>@endif
        <tr class="grand"><td>Total</td><td>Rp {{ number_format($invoice->total, 0, ',', '.') }}</td></tr>
        @if ($invoice->refunded_amount > 0)
            <tr><td>Sudah direfund</td><td>-Rp {{ number_format($invoice->refunded_amount, 0, ',', '.') }}</td></tr>
            <tr class="grand"><td>Nilai bersih</td><td>Rp {{ number_format(max(0, $invoice->total - $invoice->refunded_amount), 0, ',', '.') }}</td></tr>
        @endif
    </table>

    <section class="payments">
        <h3>PEMBAYARAN</h3>
        @foreach ($payments as $payment)
            <p>{{ $payment->method_name }}@if($payment->reference_number) · {{ $payment->reference_number }}@endif <b>Rp {{ number_format($payment->amount, 0, ',', '.') }}</b></p>
        @endforeach
        @if ($invoice->change_amount > 0)<p>Kembalian <b>Rp {{ number_format($invoice->change_amount, 0, ',', '.') }}</b></p>@endif
    </section>

    <footer class="footer">Terima kasih telah berkunjung ke Selesa Salon.</footer>
</body>
</html>
