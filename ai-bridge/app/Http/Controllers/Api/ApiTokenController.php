<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreApiTokenRequest;
use App\Models\Application;
use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class ApiTokenController extends Controller
{
    public function index(Request $request, int $app): JsonResponse
    {
        $app = $this->appVisibleTo($request)->findOrFail($app);

        return response()->json(['tokens' => $app->tokens()->latest()->get()]);
    }

    public function store(StoreApiTokenRequest $request, int $app): JsonResponse
    {
        $app = $this->appVisibleTo($request)->findOrFail($app);

        $generated = ApiToken::generate();

        $token = $app->tokens()->create([
            ...$request->validated(),
            'prefix' => $generated['prefix'],
            'token_hash' => $generated['hash'],
            'token_encrypted' => $generated['raw'],
        ]);

        return response()->json([
            'token' => $token,
            'raw_token' => $generated['raw'],
        ], 201);
    }

    public function destroy(Request $request, int $token): JsonResponse
    {
        $token = ApiToken::with('app')->findOrFail($token);
        $this->assertOwnsApp($request, $token->app);

        $token->forceFill(['revoked_at' => now()])->save();

        // Revocation must be instant — don't wait for the cache entry to expire.
        Redis::del(ApiToken::cacheKeyFor($token->token_hash));

        return response()->json(status: 204);
    }

    /**
     * Decrypts and returns the raw token value on demand. Deliberately a
     * separate, explicit call rather than something included in index() —
     * token_encrypted is hidden by default (see ApiToken::$hidden) so it's
     * never sent along with the regular token list.
     */
    public function reveal(Request $request, int $token): JsonResponse
    {
        $token = ApiToken::with('app')->findOrFail($token);
        $this->assertOwnsApp($request, $token->app);

        return response()->json(['raw_token' => $token->token_encrypted]);
    }

    /**
     * Same "members only their own, owners/admins see everything in the
     * tenant" rule as AppController — see the note there on why this is a
     * plain-int lookup rather than implicit route-model-binding.
     */
    /** @return Builder<Application> */
    private function appVisibleTo(Request $request): Builder
    {
        $user = $request->user();

        return $user->isAdmin()
            ? Application::query()
            : Application::where('user_id', $user->id);
    }

    private function assertOwnsApp(Request $request, Application $app): void
    {
        $user = $request->user();

        if (! $user->isAdmin() && $app->user_id !== $user->id) {
            abort(403);
        }
    }
}
