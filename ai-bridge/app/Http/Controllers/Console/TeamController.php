<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $members = User::where('tenant_id', $request->user()->tenant_id)->get();

        $pendingInvites = Invite::whereNull('used_at')
            ->where('expires_at', '>', now())
            ->get();

        return Inertia::render('console/team', [
            'members' => $members->map(fn ($u) => [
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'status' => 'active',
            ])->concat($pendingInvites->map(fn ($invite) => [
                'name' => '(pending)',
                'email' => $invite->email ?? '—',
                'role' => $invite->role,
                'status' => 'invited',
            ])),
        ]);
    }
}
