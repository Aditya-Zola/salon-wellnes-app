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
                @if ($logoDataUri)
                    <img class="brand-logo" src="{{ $logoDataUri }}" alt="Selesa Salon">
                @else
                    <p class="brand">selesa</p>
                @endif
                <p class="contact">{{ $salon['address'] }}</p>
                @if ($salon['whatsapp'])<p class="contact">WhatsApp: {{ $salon['whatsapp'] }}</p>@endif
                <div class="staff">
                    <p>Kasir: <strong>{{ $invoice->cashier_name ?: 'Kasir Selesa' }}</strong></p>
                    <p>Terapis: {{ $therapists->isNotEmpty() ? $therapists->join(', ') : '-' }}</p>
                </div>
            </td>
            <td>
                <table class="meta">
                    <tr><td colspan="3"><h1 style="text-align: left">{{ ($depositReceipt ?? false) ? 'NOTA DP' : (($depositAmount ?? 0) > 0 ? 'NOTA PELUNASAN' : 'NOTA PEMBAYARAN') }}</h1></td></tr>
                    <tr><td class="label">Nomor nota</td><td class="colon">:</td><td><strong>{{ ($depositReceipt ?? false) ? $invoice->number : preg_replace('/[-_\s]+/', '', $invoice->number) }}</strong></td></tr>
                    <tr><td class="label">Pelanggan</td><td class="colon">:</td><td><strong>{{ $invoice->customer_name }}</strong></td></tr>
                    <tr><td class="label">Tanggal bayar</td><td class="colon">:</td><td>{{ \Carbon\CarbonImmutable::parse($invoice->transacted_at)->translatedFormat('d F Y, H:i') }}</td></tr>
                    <tr><td class="label">Jenis bayar</td><td class="colon">:</td><td>{{ $payments->pluck('method_name')->unique()->join(' + ') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($legacyDeposit ?? false)<p class="notice">Rincian nota DP lama menggunakan data reservasi yang tersedia saat cetak.</p>@endif

    <table class="items">
        <thead><tr><th class="center" style="width:5%">No</th><th style="width:37%">Rincian</th><th style="width:13%">Jenis</th><th class="center" style="width:7%">Qty</th><th style="width:18%">Harga</th><th class="right" style="width:20%">Total</th></tr></thead>
        <tbody>
            @foreach ($displayItems as $item)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td><strong>{{ $item->name }}</strong>@if((float) ($item->returned_quantity ?? 0) > 0)<small>Diretur {{ rtrim(rtrim(number_format((float) $item->returned_quantity, 4, '.', ''), '0'), '.') }}</small>@elseif(($item->returned_amount ?? 0) > 0)<small>Refund Rp {{ number_format($item->returned_amount, 0, ',', '.') }}</small>@endif</td>
                    <td>{{ $item->item_type === 'product' ? 'Produk retail' : 'Treatment' }}</td>
                    <td class="center">{{ rtrim(rtrim(number_format((float) $item->quantity, 4, '.', ''), '0'), '.') }}</td>
                    <td style="white-space: nowrap">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                    <td class="amount">Rp {{ number_format($item->total_amount, 0, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr><td colspan="5" class="summary-label">Subtotal</td><td class="amount">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td></tr>
            @if ($invoice->discount_amount > 0)<tr><td colspan="5" class="summary-label">Diskon</td><td class="amount">-Rp {{ number_format($invoice->discount_amount, 0, ',', '.') }}</td></tr>@endif
            @if ($invoice->payment_charge_amount > 0)<tr><td colspan="5" class="summary-label">Charge pembayaran</td><td class="amount">Rp {{ number_format($invoice->payment_charge_amount, 0, ',', '.') }}</td></tr>@endif
            <tr class="grand"><td colspan="5" class="summary-label">Total</td><td class="amount">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td></tr>
            @if (($depositAmount ?? 0) > 0)
                <tr><td colspan="5" class="summary-label">{{ ($depositReceipt ?? false) ? 'DP dibayar' : 'DP sebelumnya' }}@foreach (($deposits ?? []) as $deposit)<small>{{ \App\Http\Services\DepositReceiptService::number((int) $deposit->id, $deposit->paid_at) }} &middot; {{ \Carbon\CarbonImmutable::parse($deposit->paid_at)->translatedFormat('d F Y, H:i') }}</small>@endforeach</td><td class="amount">Rp {{ number_format($depositAmount, 0, ',', '.') }}</td></tr>
                <tr class="grand"><td colspan="5" class="summary-label">{{ ($depositReceipt ?? false) ? 'Sisa pembayaran' : 'Pelunasan dibayar' }}</td><td class="amount">Rp {{ number_format(($depositReceipt ?? false) ? $remainingAmount : $settlementAmount, 0, ',', '.') }}</td></tr>
            @endif
            @if ($invoice->change_amount > 0)<tr><td colspan="5" class="summary-label">Kembalian</td><td class="amount">Rp {{ number_format($invoice->change_amount, 0, ',', '.') }}</td></tr>@endif
            @if ($invoice->refunded_amount > 0)
                <tr><td colspan="5" class="summary-label">Sudah direfund</td><td class="amount">-Rp {{ number_format($invoice->refunded_amount, 0, ',', '.') }}</td></tr>
                <tr class="grand"><td colspan="5" class="summary-label">Nilai bersih</td><td class="amount">Rp {{ number_format(max(0, $invoice->total - $invoice->refunded_amount), 0, ',', '.') }}</td></tr>
            @endif
        </tbody>
    </table>
</body>
</html>
