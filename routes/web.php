<?php

use App\Http\Controllers\Access\RoleController;
use App\Http\Controllers\Access\UserController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::view('/', 'dashboard')
        ->middleware('permission:dashboard.view')
        ->name('dashboard');

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
