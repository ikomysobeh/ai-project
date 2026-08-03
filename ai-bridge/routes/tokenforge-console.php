<?php

use App\Http\Controllers\Console\AccountsController;
use App\Http\Controllers\Console\AdminController;
use App\Http\Controllers\Console\AppsController;
use App\Http\Controllers\Console\KnowledgeController;
use App\Http\Controllers\Console\PlaygroundController;
use App\Http\Controllers\Console\TeamController;
use App\Http\Controllers\Console\TokensController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Console (Inertia dashboard) routes
|--------------------------------------------------------------------------
|
| Read-only page loads only — every mutation (create app, generate token,
| add account, upload document, invite...) goes through the existing JSON
| API (routes/api.php) via fetch from the React pages, not through these
| controllers. That reuses the already-built + tested business logic
| instead of duplicating it behind a second set of controllers.
|
*/

Route::middleware(['auth', 'verified'])->prefix('console')->group(function () {
    Route::get('apps', [AppsController::class, 'index']);
    Route::get('tokens', [TokensController::class, 'index']);
    Route::get('accounts', [AccountsController::class, 'index']);
    Route::get('knowledge', [KnowledgeController::class, 'index']);
    Route::get('playground', [PlaygroundController::class, 'index']);

    Route::middleware('role:owner,admin')->group(function () {
        Route::get('admin', [AdminController::class, 'index']);
        Route::get('team', [TeamController::class, 'index']);
    });
});
