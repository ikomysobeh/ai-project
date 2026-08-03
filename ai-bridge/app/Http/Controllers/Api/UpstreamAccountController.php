<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ReauthUpstreamAccountRequest;
use App\Http\Requests\Api\StoreUpstreamAccountRequest;
use App\Models\UpstreamAccount;
use App\Services\WebAiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user Gemini account pool (mvp-scope.md §6/§7). Deliberately scoped to
 * the authenticated user's OWN accounts only — no admin override — these
 * are personal Gemini session cookies, not a tenant-shared resource.
 */
class UpstreamAccountController extends Controller
{
    private const MAX_ACCOUNTS_PER_USER = 5;

    public function __construct(private readonly WebAiClient $webAi) {}

    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()->upstreamAccounts()->latest()->get();

        return response()->json(['accounts' => $accounts]);
    }

    public function store(StoreUpstreamAccountRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->upstreamAccounts()->count() >= self::MAX_ACCOUNTS_PER_USER) {
            return response()->json([
                'error' => ['message' => 'You already have the maximum of '.self::MAX_ACCOUNTS_PER_USER.' Gemini accounts.'],
            ], 422);
        }

        $data = $request->validated();
        $cookies = ['psid' => $data['secure_1psid'], 'psidts' => $data['secure_1psidts']];
        $check = $this->validateCookies($cookies);

        $account = UpstreamAccount::create([
            'user_id' => $user->id,
            'label' => $data['label'],
            'cookies_encrypted' => $cookies,
            'status' => $check['usable'] ? 'active' : 'expired',
        ]);

        $account->forceFill([
            'health_checked_at' => now(),
            'last_error' => $check['reason'],
        ])->save();

        return response()->json(['account' => $account], 201);
    }

    public function reauth(ReauthUpstreamAccountRequest $request, int $account): JsonResponse
    {
        $account = $request->user()->upstreamAccounts()->findOrFail($account);
        $data = $request->validated();
        $cookies = ['psid' => $data['secure_1psid'], 'psidts' => $data['secure_1psidts']];

        $account->forceFill(['cookies_encrypted' => $cookies])->save();

        $check = $this->validateCookies($cookies);
        if ($check['usable']) {
            $account->markHealthy();
        } else {
            $account->markExpired($check['reason']);
        }

        return response()->json(['account' => $account->fresh()]);
    }

    public function test(Request $request, int $account): JsonResponse
    {
        $account = $request->user()->upstreamAccounts()->findOrFail($account);

        $check = $this->validateCookies($account->cookies_encrypted);
        if ($check['usable']) {
            $account->markHealthy();
        } else {
            $account->markExpired($check['reason']);
        }

        return response()->json(['account' => $account->fresh()]);
    }

    /**
     * "Validate account on add" (mvp-scope.md §6 checklist) — a trivial
     * real call through WebAI-to-API's per-request cookie override so a bad
     * paste is caught immediately instead of on the account's first real use.
     *
     * On failure, also carries back WebAI-to-API's actual rejection reason
     * (e.g. a rotated/expired __Secure-1PSIDTS) instead of collapsing every
     * failure into a bare "expired" with no way to tell why.
     *
     * @return array{usable: bool, reason: ?string}
     */
    private function validateCookies(array $cookies): array
    {
        $response = $this->webAi->chatCompletions([
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ], $cookies);

        if ($response->successful()) {
            return ['usable' => true, 'reason' => null];
        }

        $reason = $response->json('detail')
            ?? $response->json('error.message')
            ?? ('Upstream validation failed (HTTP '.$response->status().').');

        return ['usable' => false, 'reason' => is_string($reason) ? $reason : json_encode($reason)];
    }
}
