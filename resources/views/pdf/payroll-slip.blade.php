<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 16mm; }
        * { box-sizing: border-box; }
        body { color: #2d2926; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        .header { border-bottom: 2px solid #765039; padding-bottom: 13px; text-align: center; }
        .logo { display: block; width: 205px; height: auto; margin: 0 auto 8px; }
        .brand { margin: 0; color: #765039; font-size: 24px; font-weight: bold; }
        .address { margin: 4px 0 0; color: #716862; font-size: 9px; line-height: 1.45; }
        h1 { margin: 22px 0 4px; color: #1f1b18; font-size: 17px; }
        .period { margin: 0; color: #765039; font-size: 10px; font-weight: bold; }
        .meta { width: 100%; margin: 18px 0; border-collapse: collapse; }
        .meta td { width: 33%; padding: 4px 0; vertical-align: top; }
        .meta span { color: #817871; font-size: 8px; }
        .meta b { display: block; margin-top: 2px; font-size: 10px; }
        .components { width: 100%; border-collapse: collapse; }
        .components th { padding: 8px; background: #f1ece8; color: #644432; font-size: 9px; text-align: left; }
        .components th:last-child, .components td:last-child { text-align: right; }
        .components td { padding: 7px 8px; border-bottom: 1px solid #e7e0db; }
        .components .group td { padding-top: 14px; border-bottom: 0; color: #765039; font-size: 9px; font-weight: bold; }
        .components .deduction td:last-child { color: #a54242; }
        .summary { width: 47%; margin: 17px 0 0 auto; border-collapse: collapse; }
        .summary td { padding: 6px 0; }
        .summary td:last-child { text-align: right; font-weight: bold; }
        .summary .grand td { padding-top: 10px; border-top: 1px solid #765039; color: #5d3824; font-size: 12px; }
        .signatures { width: 100%; margin-top: 48px; text-align: center; }
        .signatures td { width: 50%; color: #716862; }
        .signatures .space { height: 48px; }
        .signatures b { color: #2d2926; }
        .footer { margin-top: 25px; border-top: 1px solid #dfd6cf; padding-top: 10px; color: #817871; font-size: 8px; text-align: center; }
    </style>
</head>
<body>
    <header class="header">
        @if ($logoDataUri)
            <img class="logo" src="{{ $logoDataUri }}" alt="Selesa Salon">
        @else
            <p class="brand">SELESA SALON &amp; SPA</p>
        @endif
        <p class="address">{{ $salon['address'] }}@if($salon['whatsapp'])<br>WhatsApp: {{ $salon['whatsapp'] }}@endif</p>
    </header>

    <h1>TANDA TERIMA PENDAPATAN</h1>
    <p class="period">PERIODE {{ mb_strtoupper($periodLabel) }}</p>

    <table class="meta">
        <tr>
            <td><span>Nama</span><b>{{ $employee['employee_name'] }}</b></td>
            <td><span>Posisi</span><b>{{ $employee['position'] ?: '-' }}</b></td>
            <td><span>JHK</span><b>{{ rtrim(rtrim(number_format((float) $employee['paid_work_days'], 2, ',', '.'), '0'), ',') }} hari</b></td>
        </tr>
        <tr>
            <td><span>Tidak masuk / mangkir</span><b>{{ rtrim(rtrim(number_format((float) $employee['absence_days'], 2, ',', '.'), '0'), ',') }} hari</b></td>
            <td><span>Keterlambatan</span><b>{{ number_format($employee['late_minutes'], 0, ',', '.') }} menit</b></td>
            <td><span>Komisi layanan</span><b>{{ number_format($employee['treatment_count'], 0, ',', '.') }} treatment</b></td>
        </tr>
    </table>

    <table class="components">
        <thead><tr><th>KOMPONEN PENDAPATAN</th><th>NILAI</th></tr></thead>
        <tbody>
            <tr><td>Gaji pokok</td><td>Rp {{ number_format($employee['base_salary'], 0, ',', '.') }}</td></tr>
            <tr><td>Komisi</td><td>Rp {{ number_format($employee['commission'], 0, ',', '.') }}</td></tr>
            <tr><td>Lembur{{ $employee['overtime_days'] > 0 ? ' ('.rtrim(rtrim(number_format((float) $employee['overtime_days'], 2, ',', '.'), '0'), ',').' hari)' : '' }}</td><td>Rp {{ number_format($employee['overtime'], 0, ',', '.') }}</td></tr>
            <tr><td>Uang makan</td><td>Rp {{ number_format($employee['meal_allowance'], 0, ',', '.') }}</td></tr>
            <tr><td>Tunjangan</td><td>Rp {{ number_format($employee['total_allowance'], 0, ',', '.') }}</td></tr>
            <tr><td>Bonus</td><td>Rp {{ number_format($employee['total_bonus'], 0, ',', '.') }}</td></tr>
            <tr><td>Titipan TIP</td><td>Rp {{ number_format($employee['tip_deposit'], 0, ',', '.') }}</td></tr>
            <tr class="group"><td colspan="2">POTONGAN</td></tr>
            <tr class="deduction"><td>Absensi / mangkir</td><td>-Rp {{ number_format($employee['absence_deduction'], 0, ',', '.') }}</td></tr>
            <tr class="deduction"><td>Potongan keterlambatan</td><td>-Rp {{ number_format($employee['late_deduction'], 0, ',', '.') }}</td></tr>
            <tr class="deduction"><td>Kasbon</td><td>-Rp {{ number_format($employee['cash_advance'], 0, ',', '.') }}</td></tr>
            <tr class="deduction"><td>Potongan lain</td><td>-Rp {{ number_format($employee['other_deduction'], 0, ',', '.') }}</td></tr>
        </tbody>
    </table>

    <table class="summary">
        <tr><td>Pendapatan kotor</td><td>Rp {{ number_format($employee['gross_income'], 0, ',', '.') }}</td></tr>
        <tr><td>Jumlah potongan</td><td>-Rp {{ number_format($employee['total_deduction'], 0, ',', '.') }}</td></tr>
        <tr class="grand"><td>TOTAL GAJI DITERIMA</td><td>Rp {{ number_format($employee['net_salary'], 0, ',', '.') }}</td></tr>
    </table>

    <table class="signatures">
        <tr><td>Semarang, {{ now()->translatedFormat('d F Y') }}</td><td>Semarang, {{ now()->translatedFormat('d F Y') }}</td></tr>
        <tr><td>Penerima,</td><td>Manager / Owner,</td></tr>
        <tr class="space"><td></td><td></td></tr>
        <tr><td><b>{{ $employee['employee_name'] }}</b></td><td><b>{{ $printedBy }}</b></td></tr>
    </table>

    <p class="footer">Slip dibuat dari data remunerasi Selesa Salon. Catatan: {{ $employee['notes'] ?: '-' }}</p>
</body>
</html>
