<?php

use App\Http\Controllers\Api\GatewayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gateway routes
|--------------------------------------------------------------------------
|
| The OpenAI-compatible surface external clients call directly (mvp-scope.md
| §7). Deliberately NOT under routes/api.php's "api" group/prefix — that
| prefix is an internal convention for our own management API, but this path
| needs to match exactly what OpenAI-client SDKs/tools expect when pointed
| at a custom base URL, so it lives at bare /v1/*, not /api/v1/*.
|
*/

Route::middleware('api.token')->prefix('v1')->group(function () {
    // Rate/quota limits only apply to the actual generation call — listing
    // models is a metadata lookup, not something worth spending quota on.
    Route::post('chat/completions', [GatewayController::class, 'chatCompletions'])
        ->middleware('api.limits');
    Route::get('models', [GatewayController::class, 'models']);
});
