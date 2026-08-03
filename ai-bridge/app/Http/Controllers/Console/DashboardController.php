<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApiToken;
use App\Models\UpstreamAccount;
use App\Models\UsageRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $apps = $this->visibleApps($user);
        $appIds = (clone $apps)->pluck('id');

        $usage = UsageRecord::whereIn('app_id', $appIds);
        $last24h = (clone $usage)->where('created_at', '>=', now()->subDay());
        $prev24h = (clone $usage)->whereBetween('created_at', [now()->subDays(2), now()->subDay()]);

        $requests24h = (clone $last24h)->count();
        $requestsPrev24h = (clone $prev24h)->count();
        $errors24h = (clone $last24h)->where('status', 'error')->count();

        $activeAccounts = UpstreamAccount::where('user_id', $user->id)->where('status', 'active')->count();
        $totalAccounts = UpstreamAccount::where('user_id', $user->id)->count();

        return Inertia::render('dashboard', [
            'stats' => [
                'requests24h' => $requests24h,
                'requestsDeltaPct' => $this->deltaPct($requests24h, $requestsPrev24h),
                'activeTokens' => ApiToken::whereIn('app_id', $appIds)->whereNull('revoked_at')->count(),
                'totalTokens' => ApiToken::whereIn('app_id', $appIds)->count(),
                'errorRatePct' => $requests24h > 0 ? round(($errors24h / $requests24h) * 100, 1) : 0,
                'poolActive' => $activeAccounts,
                'poolTotal' => $totalAccounts,
            ],
            'sparkline' => $this->dailyCounts($appIds),
            'problems' => $this->problems($user),
            'topApps' => $this->topApps($apps),
        ]);
    }

    /** @return Builder<Application> */
    private function visibleApps(User $user): Builder
    {
        return $user->isAdmin() ? Application::query() : Application::where('user_id', $user->id);
    }

    private function deltaPct(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * @param  Collection<int, int>  $appIds
     * @return array<int, int> request counts for each of the last 14 days
     */
    private function dailyCounts(Collection $appIds): array
    {
        $rows = UsageRecord::whereIn('app_id', $appIds)
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $days = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $days[] = (int) ($rows[$date] ?? 0);
        }

        return $days;
    }

    /** @return array<int, array{title: string, detail: string, kind: string}> */
    private function problems(User $user): array
    {
        $problems = [];

        foreach (UpstreamAccount::where('user_id', $user->id)->where('status', 'expired')->get() as $account) {
            $problems[] = [
                'title' => 'Account expired',
                'detail' => "{$account->label} cookie died — re-auth needed",
                'kind' => 'expired',
            ];
        }

        foreach (UpstreamAccount::where('user_id', $user->id)->where('status', 'cooling_down')->get() as $account) {
            $problems[] = [
                'title' => 'Account cooling down',
                'detail' => "{$account->label} hit a rate limit, resting",
                'kind' => 'cooling',
            ];
        }

        return $problems;
    }

    /**
     * @param  Builder<Application>  $apps
     * @return array<int, array{id: int, name: string, requests: int}>
     */
    private function topApps(Builder $apps): array
    {
        return (clone $apps)
            ->get()
            ->map(fn ($app) => [
                'id' => $app->id,
                'name' => $app->name,
                'requests' => DB::table('usage_records')->where('app_id', $app->id)->count(),
            ])
            ->sortByDesc('requests')
            ->take(5)
            ->values()
            ->all();
    }
}
