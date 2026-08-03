<?php

use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\InviteAcceptController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('invite/{token}', [InviteAcceptController::class, 'show'])->name('invite.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/tokenforge-console.php';
