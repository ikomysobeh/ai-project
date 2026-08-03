<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Application;
use App\Models\Invite;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;
        $appIds = Application::pluck('id'); // already tenant-scoped via BelongsToTenant

        $usage30d = UsageRecord::whereIn('app_id', $appIds)->where('created_at', '>=', now()->subDays(30));

        return Inertia::render('console/admin', [
            'stats' => [
                'members' => User::where('tenant_id', $tenantId)->count(),
                'pendingInvites' => Invite::whereNull('used_at')->where('expires_at', '>', now())->count(),
                'requests30d' => (clone $usage30d)->count(),
                'failed30d' => (clone $usage30d)->where('status', 'error')->count(),
                'tokensIssued' => ApiToken::whereIn('app_id', $appIds)->count(),
                'tokensActive' => ApiToken::whereIn('app_id', $appIds)->whereNull('revoked_at')->count(),
            ],
            'usageByMember' => $this->usageByMember($tenantId),
        ]);
    }

    /** @return array<int, array{name: string, role: string, apps: string, requests30d: int, errors30d: int, tokens: int}> */
    private function usageByMember(int $tenantId): array
    {
        return User::where('tenant_id', $tenantId)
            ->get()
            ->map(function ($user) {
                $apps = Application::where('user_id', $user->id)->get(['id', 'name']);
                $appIds = $apps->pluck('id');
                $usage = UsageRecord::whereIn('app_id', $appIds)->where('created_at', '>=', now()->subDays(30));

                return [
                    'name' => $user->name,
                    'role' => $user->role,
                    'apps' => $apps->pluck('name')->implode(', ') ?: '—',
                    'requests30d' => (clone $usage)->count(),
                    'errors30d' => (clone $usage)->where('status', 'error')->count(),
                    'tokens' => ApiToken::whereIn('app_id', $appIds)->whereNull('revoked_at')->count(),
                ];
            })
            ->all();
    }
}
