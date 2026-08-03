<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApiToken;
use App\Models\UsageRecord;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TokensController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $apps = $user->isAdmin() ? Application::query() : Application::where('user_id', $user->id);
        $appIds = (clone $apps)->pluck('id');

        $usedToday = UsageRecord::whereIn('app_id', $appIds)
            ->where('created_at', '>=', now()->startOfDay())
            ->whereNotNull('token_id')
            ->selectRaw('token_id, COUNT(*) as total')
            ->groupBy('token_id')
            ->pluck('total', 'token_id');

        $tokens = ApiToken::whereIn('app_id', $appIds)->with('app')->latest()->get();

        return Inertia::render('console/tokens', [
            'apps' => (clone $apps)->get(['id', 'name']),
            'tokens' => $tokens->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'prefix' => $token->prefix,
                'app' => $token->app?->name,
                'model' => $token->app?->default_model,
                'used_today' => $usedToday[$token->id] ?? 0,
                'daily_quota' => $token->daily_quota,
                'last_used_at' => $token->last_used_at?->diffForHumans(),
                'revoked' => $token->revoked_at !== null,
            ]),
        ]);
    }
}
