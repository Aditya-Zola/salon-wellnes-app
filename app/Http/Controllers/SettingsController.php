<?php

namespace App\Http\Controllers;

use App\Http\Services\ActivityLogger;
use App\Http\Support\FixedPoint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const SECTIONS = [
        'edc' => ['type' => 'card', 'title' => 'EDC', 'source_label' => 'Nama EDC'],
        'bank' => ['type' => 'bank_transfer', 'title' => 'Bank', 'source_label' => 'Nama bank'],
        'qris' => ['type' => 'qris', 'title' => 'QRIS', 'source_label' => 'Nama QRIS'],
    ];

    public function __construct(private readonly ActivityLogger $logger) {}

    public function sale(): View
    {
        $settings = DB::table('sale_settings')
            ->whereIn('key', ['invoice_prefix', 'salon_address', 'salon_whatsapp'])
            ->pluck('value', 'key');

        return view('settings.sale', [
            'invoicePrefix' => $settings->get('invoice_prefix') ?: 'INV',
            'salonAddress' => $settings->get('salon_address') ?: 'Jl. Telaga Asmara, Tlogosari Kulon, Semarang',
            'salonWhatsapp' => $settings->get('salon_whatsapp') ?: '081128702019',
        ]);
    }

    public function updateSale(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'invoice_prefix' => ['required', 'string', 'min:1', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'salon_address' => ['nullable', 'string', 'max:255'],
            'salon_whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
        ]);
        $now = now();
        $prefix = Str::upper(trim($data['invoice_prefix']));

        DB::table('sale_settings')->updateOrInsert(
            ['key' => 'invoice_prefix'],
            ['value' => $prefix, 'updated_at' => $now, 'created_at' => $now],
        );
        foreach (['salon_address', 'salon_whatsapp'] as $key) {
            if (! $request->has($key)) {
                continue;
            }

            DB::table('sale_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => trim((string) ($data[$key] ?? '')), 'updated_at' => $now, 'created_at' => $now],
            );
        }
        $this->logger->log($request, 'settings.invoice_prefix_updated', 'sale_setting', null, 'Memperbarui prefix invoice menjadi '.$prefix);

        return back()->with('success', 'Prefix invoice berhasil disimpan.');
    }

    public function paymentMethods(Request $request, string $section): View
    {
        $config = $this->section($section);
        $methods = DB::table('payment_methods')
            ->where('type', $config['type'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
        $editMethod = $request->integer('edit')
            ? $methods->firstWhere('id', $request->integer('edit'))
            : null;

        return view('settings.payment-methods', compact('section', 'config', 'methods', 'editMethod'));
    }

    public function storePaymentMethod(Request $request, string $section): RedirectResponse
    {
        $config = $this->section($section);
        $data = $this->validatedPaymentMethod($request, $section);
        $now = now();
        $id = DB::table('payment_methods')->insertGetId([
            'code' => $this->nextCode($section),
            'name' => $this->paymentName($data['source_name']),
            'account_name' => $data['account_name'],
            'account_number' => $data['account_number'],
            'type' => $config['type'],
            'is_cash' => false,
            'requires_reference' => true,
            'charge_percent' => $data['charge_percent'],
            'charge_default_enabled' => $data['charge_default_enabled'],
            'is_active' => $data['is_active'],
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->logger->log($request, 'settings.payment_method_created', 'payment_method', $id, 'Menambahkan '.$config['title'].' '.$data['source_name']);

        return redirect()->route('settings.payment-methods.index', $section)->with('success', $config['title'].' berhasil ditambahkan.');
    }

    public function updatePaymentMethod(Request $request, string $section, int $paymentMethod): RedirectResponse
    {
        $config = $this->section($section);
        $method = $this->methodInSection($paymentMethod, $config['type']);
        $data = $this->validatedPaymentMethod($request, $section);

        DB::table('payment_methods')->where('id', $method->id)->update([
            'code' => $method->code,
            'name' => $this->paymentName($data['source_name']),
            'account_name' => $data['account_name'],
            'account_number' => $data['account_number'],
            'charge_percent' => $data['charge_percent'],
            'charge_default_enabled' => $data['charge_default_enabled'],
            'is_active' => $data['is_active'],
            'updated_at' => now(),
        ]);
        $this->logger->log($request, 'settings.payment_method_updated', 'payment_method', $method->id, 'Memperbarui '.$config['title'].' '.$data['source_name']);

        return redirect()->route('settings.payment-methods.index', $section)->with('success', $config['title'].' berhasil diperbarui.');
    }

    public function togglePaymentMethod(Request $request, string $section, int $paymentMethod): RedirectResponse
    {
        $config = $this->section($section);
        $method = $this->methodInSection($paymentMethod, $config['type']);
        $active = ! (bool) $method->is_active;
        DB::table('payment_methods')->where('id', $method->id)->update(['is_active' => $active, 'updated_at' => now()]);
        $this->logger->log($request, 'settings.payment_method_toggled', 'payment_method', $method->id, ($active ? 'Mengaktifkan ' : 'Menonaktifkan ').$method->name);

        return back()->with('success', $method->name.($active ? ' diaktifkan.' : ' dinonaktifkan.'));
    }

    private function section(string $section): array
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);

        return self::SECTIONS[$section];
    }

    private function methodInSection(int $id, string $type): object
    {
        return DB::table('payment_methods')->where('id', $id)->where('type', $type)->firstOrFail();
    }

    private function validatedPaymentMethod(Request $request, string $section): array
    {
        $withAccount = in_array($section, ['bank', 'qris'], true);
        $data = $request->validate([
            'source_name' => ['required', 'string', 'max:100'],
            'account_name' => [$withAccount ? 'required' : 'nullable', 'string', 'max:150'],
            'account_number' => [$withAccount ? 'required' : 'nullable', 'string', 'max:100'],
            'charge_percent' => ['nullable', 'regex:/^\d{1,3}(?:\.\d{1,4})?$/'],
            'charge_default_enabled' => ['nullable', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $chargePercent = $data['charge_percent'] ?? '0';
        if (FixedPoint::parse((string) $chargePercent, FixedPoint::PERCENT_SCALE) > 100 * (10 ** FixedPoint::PERCENT_SCALE)) {
            throw ValidationException::withMessages(['charge_percent' => ['Persentase charge tidak boleh lebih dari 100.']]);
        }

        return [
            ...$data,
            'source_name' => trim($data['source_name']),
            'account_name' => $withAccount ? trim((string) $data['account_name']) : null,
            'account_number' => $withAccount ? trim((string) $data['account_number']) : null,
            'charge_percent' => FixedPoint::normalizePercent((string) $chargePercent),
            'charge_default_enabled' => $request->boolean('charge_default_enabled', true),
            'is_active' => (bool) $data['is_active'],
        ];
    }

    private function paymentName(string $sourceName): string
    {
        return $sourceName;
    }

    private function nextCode(string $section): string
    {
        $prefix = strtoupper($section);
        $lastCode = DB::table('payment_methods')
            ->where('code', 'like', $prefix.'-%')
            ->orderByDesc('id')
            ->value('code');
        $lastNumber = $lastCode && preg_match('/-(\d+)$/', $lastCode, $matches)
            ? (int) $matches[1]
            : 0;

        do {
            $lastNumber++;
            $code = sprintf('%s-%03d', $prefix, $lastNumber);
        } while (DB::table('payment_methods')->where('code', $code)->exists());

        return $code;
    }
}
