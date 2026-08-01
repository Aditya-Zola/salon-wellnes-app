<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return redirect()->route(match (auth()->user()->role) {
            'super_admin' => 'portal.super-admin',
            'admin' => 'portal.admin',
            'marketing' => 'portal.marketing',
            'kasir' => 'portal.kasir',
        });
    })->name('dashboard');

    Route::view('/super-admin', 'dashboard', ['portal' => 'super_admin'])
        ->middleware('role:super_admin')->name('portal.super-admin');
    Route::view('/admin', 'dashboard', ['portal' => 'admin'])
        ->middleware('role:admin')->name('portal.admin');
    Route::view('/marketing', 'dashboard', ['portal' => 'marketing'])
        ->middleware('role:marketing')->name('portal.marketing');
    Route::view('/kasir', 'dashboard', ['portal' => 'kasir'])
        ->middleware('role:kasir')->name('portal.kasir');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
