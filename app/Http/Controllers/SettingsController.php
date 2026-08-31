<?php

namespace App\Http\Controllers;

use App\Http\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    private const SECTIONS = [
        'edc' => ['type' => 'card', 'title' => 'EDC', 'source_label' => 'Nama EDC'],
        'bank' => ['type' => 'bank_transfer', 'title' => 'Bank', 'source_label' => 'Nama bank'],
        'qris' => ['type' => 'qris', 'title' => 'QRIS', 'source_label' => 'Nama QRIS'],
    ];

    public function __construct(private readonly ActivityLogger $logger) {}

    public function sale(): \Illuminate\View\View
    {
        return view('settings.sale', [
            'invoicePrefix' => DB::table('sale_settings')->where('key', 'invoice_prefix')->value('value') ?: 'INV',
        ]);
    }

    public function updateSale(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'invoice_prefix' => ['required', 'string', 'min:1', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $now = now();
        $prefix = Str::upper(trim($data['invoice_prefix']));

        DB::table('sale_settings')->updateOrInsert(
            ['key' => 'invoice_prefix'],
            ['value' => $prefix, 'updated_at' => $now, 'created_at' => $now],
        );
        $this->logger->log($request, 'settings.invoice_prefix_updated', 'sale_setting', null, 'Memperbarui prefix invoice menjadi '.$prefix);

        return back()->with('success', 'Prefix invoice berhasil disimpan.');
    }

    public function paymentMethods(Request $request, string $section): \Illuminate\View\View
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
            'is_active' => ['required', 'boolean'],
        ]);

        return [
            ...$data,
            'source_name' => trim($data['source_name']),
            'account_name' => $withAccount ? trim((string) $data['account_name']) : null,
            'account_number' => $withAccount ? trim((string) $data['account_number']) : null,
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
