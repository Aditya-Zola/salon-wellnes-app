<?php

use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\UserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SalonController;
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
        Route::post('/reservasi', [SalonController::class, 'storeReservation'])->middleware('permission:reservations.create')->name('reservations.store');
        Route::get('/reservasi/terapis-tersedia', [SalonController::class, 'availableTherapists'])->middleware('permission:reservations.view|reservations.create')->name('reservations.therapists');
        Route::patch('/reservasi/{reservation}/item/{item}/status', [SalonController::class, 'updateReservationItemStatus'])->middleware('permission:reservations.update')->name('reservations.items.status');
        Route::patch('/reservasi/{id}', [SalonController::class, 'updateReservation'])->middleware('permission:reservations.update')->name('reservations.update');
        Route::post('/pegawai', [SalonController::class, 'storeEmployee'])->middleware('permission:employees.create')->name('employees.store');
        Route::patch('/pegawai/{id}', [SalonController::class, 'updateEmployee'])->middleware('permission:employees.update')->name('employees.update');
        Route::post('/produk', [SalonController::class, 'storeProduct'])->middleware('permission:products.create')->name('products.store');
        Route::patch('/produk/{id}/stok', [SalonController::class, 'adjustStock'])->middleware('permission:products.update')->name('products.stock');
        Route::post('/treatment', [SalonController::class, 'storeTreatment'])->middleware('permission:treatments.create')->name('treatments.store');
        Route::put('/treatment/{id}/resep', [SalonController::class, 'updateRecipe'])->middleware('permission:treatments.update')->name('treatments.recipe');
        Route::post('/member', [SalonController::class, 'storeMember'])->middleware('permission:memberships.manage')->name('members.store');
        Route::post('/pembayaran', [SalonController::class, 'storePayment'])->middleware('permission:cashier.process')->name('payments.store');
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
        Route::put('/pengguna/{user}', [UserController::class, 'update'])
            ->middleware('permission:access.users.manage')
            ->name('users.update');
        Route::delete('/pengguna/{user}', [UserController::class, 'destroy'])
            ->middleware('permission:access.users.manage')
            ->name('users.destroy');
    });

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
