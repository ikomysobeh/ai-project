<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\UpstreamAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountsController extends Controller
{
    public function index(Request $request): Response
    {
        // Deliberately the authenticated user's own pool only — accounts are
        // personal Gemini credentials, never tenant-shared (mvp-scope.md §2).
        $accounts = UpstreamAccount::where('user_id', $request->user()->id)
            ->withCount('usageRecords')
            ->latest()
            ->get();

        return Inertia::render('console/accounts', [
            'accounts' => $accounts->map(fn ($a) => [
                'id' => $a->id,
                'label' => $a->label,
                'status' => $a->status,
                'requests' => $a->usage_records_count,
                'last_used_at' => $a->last_used_at?->diffForHumans(),
                'last_error' => $a->last_error,
            ]),
            'stats' => [
                'total' => $accounts->count(),
                'active' => $accounts->where('status', 'active')->count(),
                'cooling' => $accounts->where('status', 'cooling_down')->count(),
                'expired' => $accounts->where('status', 'expired')->count(),
            ],
        ]);
    }
}
