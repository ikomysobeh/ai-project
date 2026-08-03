<?php

namespace App\Http\Controllers;

use App\Models\Invite;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public page for Flow A (mvp-scope.md §5) — the actual API logic already
 * lives in Api\InviteController::accept; this just renders the form that
 * posts to it. Same "look up by the token itself, bypass the tenant scope"
 * reasoning as that endpoint — nobody is authenticated yet.
 */
class InviteAcceptController extends Controller
{
    public function show(string $token): Response
    {
        $invite = Invite::withoutGlobalScopes()->where('signed_token', $token)->with('tenant')->first();

        if ($invite === null) {
            return Inertia::render('auth/invite-accept', ['invite' => null]);
        }

        return Inertia::render('auth/invite-accept', [
            'invite' => [
                'token' => $token,
                'tenant_name' => $invite->tenant->name,
                'role' => $invite->role,
                'email' => $invite->email,
                'expired' => $invite->expires_at->isPast(),
                'used' => $invite->used_at !== null,
            ],
        ]);
    }
}
