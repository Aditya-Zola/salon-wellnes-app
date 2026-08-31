<?php

use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SalonController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [SalonController::class, 'dashboard'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard');

    Route::prefix('operasional')->name('operations.')->group(function () {
        Route::get('/data', [SalonController::class, 'data'])->name('data');
        Route::get('/penjualan', [SalonController::class, 'salesPage'])->middleware('permission:sales.view')->name('sales.page');
        Route::get('/retur', [SalonController::class, 'salesReturnsPage'])->middleware('permission:sales.view')->name('sales.returns.page');
        Route::get('/produk', [SalonController::class, 'productsPage'])->middleware('permission:products.view')->name('products.page');
        Route::get('/produk/riwayat', [SalonController::class, 'stockHistoryPage'])->middleware('permission:products.view')->name('stock.history.page');
        Route::post('/produk/import', [SalonController::class, 'importProducts'])->middleware('permission:products.create')->name('products.import');
        Route::get('/reservasi/ekspor', [SalonController::class, 'exportSchedule'])->middleware('permission:reservations.view')->name('reservations.export');
        Route::get('/produk/riwayat-ekspor', [SalonController::class, 'exportStockHistory'])->middleware('permission:products.view')->name('stock.export');
        Route::post('/reservasi', [SalonController::class, 'storeReservation'])->middleware('permission:reservations.create')->name('reservations.store');
        Route::post('/kasir/transaksi', [SalonController::class, 'storeReservation'])->middleware('permission:cashier.process')->name('cashier.transactions.store');
        Route::post('/reservasi/{reservation}/item', [SalonController::class, 'storeReservationItem'])->middleware('permission:cashier.process')->name('reservations.items.store');
        Route::post('/reservasi/{reservation}/produk', [SalonController::class, 'storeReservationProduct'])->middleware('permission:cashier.process')->name('reservations.products.store');
        Route::delete('/reservasi/{reservation}/produk/{product}', [SalonController::class, 'destroyReservationProduct'])->middleware('permission:cashier.process')->name('reservations.products.destroy');
        Route::get('/reservasi/terapis-tersedia', [SalonController::class, 'availableTherapists'])->middleware('permission:reservations.view|reservations.create')->name('reservations.therapists');
        Route::get('/therapist-kehadiran', [SalonController::class, 'therapistAttendance'])->middleware('permission:therapist_attendance.view')->name('therapists.attendance');
        Route::put('/therapist-kehadiran/{employee}', [SalonController::class, 'updateTherapistAttendance'])->middleware('permission:therapist_attendance.manage')->name('therapists.attendance.update');
        Route::patch('/reservasi/{reservation}/item/{item}/status', [SalonController::class, 'updateReservationItemStatus'])->middleware('permission:reservations.update')->name('reservations.items.status');
        Route::patch('/reservasi/{id}', [SalonController::class, 'updateReservation'])->middleware('permission:reservations.update')->name('reservations.update');
        Route::post('/pegawai', [SalonController::class, 'storeEmployee'])->middleware('permission:employees.create')->name('employees.store');
        Route::patch('/pegawai/{id}', [SalonController::class, 'updateEmployee'])->middleware('permission:employees.update')->name('employees.update');
        Route::post('/produk', [SalonController::class, 'storeProduct'])->middleware('permission:products.create')->name('products.store');
        Route::patch('/produk/{id}', [SalonController::class, 'updateProduct'])->middleware('permission:products.update')->name('products.update');
        Route::patch('/produk/{id}/harga', [SalonController::class, 'updateProductPrice'])->middleware('permission:products.update')->name('products.price');
        Route::patch('/produk/{id}/stok', [SalonController::class, 'adjustStock'])->middleware('permission:products.update')->name('products.stock');
        Route::post('/treatment', [SalonController::class, 'storeTreatment'])->middleware('permission:treatments.create')->name('treatments.store');
        Route::patch('/treatment/{id}/komisi', [SalonController::class, 'updateTreatmentCommission'])->middleware('permission:treatments.update')->name('treatments.commission.update');
        Route::put('/treatment/{id}/resep', [SalonController::class, 'updateRecipe'])->middleware('permission:treatments.update')->name('treatments.recipe');
        Route::get('/member', [SalonController::class, 'membersPage'])->middleware('permission:memberships.view|memberships.manage')->name('members.page');
        Route::post('/member', [SalonController::class, 'storeMember'])->middleware('permission:memberships.manage')->name('members.store');
        Route::patch('/member/{id}', [SalonController::class, 'updateMember'])->middleware('permission:memberships.manage')->name('members.update');
        Route::delete('/member/{id}', [SalonController::class, 'destroyMember'])->middleware('permission:memberships.manage')->name('members.destroy');
        Route::post('/promo', [SalonController::class, 'storePromotion'])->middleware('permission:memberships.manage')->name('promotions.store');
        Route::patch('/promo/{id}', [SalonController::class, 'updatePromotion'])->middleware('permission:memberships.manage')->name('promotions.update');
        Route::delete('/promo/{id}', [SalonController::class, 'destroyPromotion'])->middleware('permission:memberships.manage')->name('promotions.destroy');
        Route::post('/pembayaran', [SalonController::class, 'storePayment'])->middleware('permission:cashier.process')->name('payments.store');
        Route::get('/penjualan/{transaction}/nota.pdf', [SalonController::class, 'invoicePdf'])->middleware('permission:cashier.process|sales.view')->name('sales.invoice.pdf');
        Route::post('/penjualan/{transaction}/retur', [SalonController::class, 'storeSalesReturn'])->middleware('permission:cashier.refund')->name('sales.returns.store');
        Route::get('/retur/{salesReturn}/struk.pdf', [SalonController::class, 'salesReturnPdf'])->middleware('permission:cashier.refund|sales.view')->name('sales.returns.receipt.pdf');
        Route::post('/keuangan/arus-kas', [SalonController::class, 'storeCashEntry'])->middleware('permission:finance.manage')->name('finance.cash-entries.store');
        Route::post('/penggajian', [SalonController::class, 'storePayroll'])->middleware('permission:payroll.manage')->name('payroll.store');
        Route::patch('/penggajian/{id}', [SalonController::class, 'updatePayroll'])->middleware('permission:payroll.manage')->name('payroll.update');
    });

    Route::redirect('/super-admin', '/');
    Route::redirect('/admin', '/');
    Route::redirect('/marketing', '/');
    Route::redirect('/kasir', '/');

    Route::prefix('hak-akses')->name('access.')->group(function () {
        Route::get('/peran', [RoleController::class, 'index'])
            ->middleware('permission:access.roles.view')
            ->name('roles.index');
        Route::post('/peran', [RoleController::class, 'store'])
            ->middleware('permission:access.roles.manage')
            ->name('roles.store');
        Route::get('/peran/{role}/edit', [RoleController::class, 'edit'])
            ->middleware('permission:access.roles.view')
            ->name('roles.edit');
        Route::put('/peran/{role}', [RoleController::class, 'update'])
            ->middleware('permission:access.roles.manage')
            ->name('roles.update');
        Route::delete('/peran/{role}', [RoleController::class, 'destroy'])
            ->middleware('permission:access.roles.manage')
            ->name('roles.destroy');

        Route::get('/pengguna', [UserController::class, 'index'])
            ->middleware('permission:access.users.view')
            ->name('users.index');
        Route::post('/pengguna', [UserController::class, 'store'])
            ->middleware('permission:access.users.manage')
            ->name('users.store');
        Route::get('/pengguna/{user}/edit', [UserController::class, 'edit'])
            ->middleware('permission:access.users.view')
            ->name('users.edit');
        Route::get('/pengguna/karyawan/{employee}/edit', [UserController::class, 'editEmployee'])
            ->middleware('permission:access.users.view')
            ->name('users.employees.edit');
        Route::put('/pengguna/{user}', [UserController::class, 'update'])
            ->middleware('permission:access.users.manage')
            ->name('users.update');
        Route::put('/pengguna/karyawan/{employee}', [UserController::class, 'updateEmployee'])
            ->middleware('permission:access.users.manage')
            ->name('users.employees.update');
        Route::delete('/pengguna/karyawan/{employee}', [UserController::class, 'destroyEmployee'])
            ->middleware('permission:access.users.manage')
            ->name('users.employees.destroy');
        Route::delete('/pengguna/{user}', [UserController::class, 'destroy'])
            ->middleware('permission:access.users.manage')
            ->name('users.destroy');
    });

    Route::prefix('pengaturan')->name('settings.')->middleware('permission:settings.manage')->group(function () {
        Route::get('/penjualan', [SettingsController::class, 'sale'])->name('sale');
        Route::patch('/penjualan', [SettingsController::class, 'updateSale'])->name('sale.update');
        Route::get('/{section}', [SettingsController::class, 'paymentMethods'])->whereIn('section', ['edc', 'bank', 'qris'])->name('payment-methods.index');
        Route::post('/{section}', [SettingsController::class, 'storePaymentMethod'])->whereIn('section', ['edc', 'bank', 'qris'])->name('payment-methods.store');
        Route::patch('/{section}/{paymentMethod}', [SettingsController::class, 'updatePaymentMethod'])->whereIn('section', ['edc', 'bank', 'qris'])->name('payment-methods.update');
        Route::patch('/{section}/{paymentMethod}/status', [SettingsController::class, 'togglePaymentMethod'])->whereIn('section', ['edc', 'bank', 'qris'])->name('payment-methods.toggle');
    });

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
