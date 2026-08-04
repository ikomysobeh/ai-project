<?php

use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\AppController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\InviteController;
use App\Http\Controllers\Api\KnowledgeBaseController;
use App\Http\Controllers\Api\UpstreamAccountController;
use App\Http\Controllers\Console\PlaygroundController;
use Illuminate\Support\Facades\Route;

Route::post('auth/signup', [AuthController::class, 'signup'])->middleware('throttle:6,1');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::post('invites/{token}/accept', [InviteController::class, 'accept'])->middleware('throttle:6,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::middleware('role:owner,admin')->group(function () {
        Route::post('invites', [InviteController::class, 'store']);
    });

    Route::get('apps', [AppController::class, 'index']);
    Route::post('apps', [AppController::class, 'store']);
    Route::delete('apps/{app}', [AppController::class, 'destroy']);
    Route::post('apps/{app}/attach-kb', [AppController::class, 'attachKnowledgeBase']);

    Route::get('apps/{app}/tokens', [ApiTokenController::class, 'index']);
    Route::post('apps/{app}/tokens', [ApiTokenController::class, 'store']);
    Route::delete('tokens/{token}', [ApiTokenController::class, 'destroy']);
    Route::post('tokens/{token}/reveal', [ApiTokenController::class, 'reveal']);

    Route::get('accounts', [UpstreamAccountController::class, 'index']);
    Route::post('accounts', [UpstreamAccountController::class, 'store']);
    Route::post('accounts/{account}/reauth', [UpstreamAccountController::class, 'reauth']);
    Route::post('accounts/{account}/test', [UpstreamAccountController::class, 'test']);

    Route::get('knowledge-bases', [KnowledgeBaseController::class, 'index']);
    Route::post('knowledge-bases', [KnowledgeBaseController::class, 'store']);
    Route::get('knowledge-bases/{knowledgeBase}/documents', [DocumentController::class, 'index']);
    Route::post('knowledge-bases/{knowledgeBase}/documents', [DocumentController::class, 'store']);
    Route::delete('documents/{document}', [DocumentController::class, 'destroy']);

    Route::post('playground/{app}/send', [PlaygroundController::class, 'send']);
});
