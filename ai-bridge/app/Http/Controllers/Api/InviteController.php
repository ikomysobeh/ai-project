<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AcceptInviteRequest;
use App\Http\Requests\Api\StoreInviteRequest;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteController extends Controller
{
    public function store(StoreInviteRequest $request): JsonResponse
    {
        $invite = Invite::create([
            ...$request->validated(),
            'signed_token' => Str::random(48),
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(['invite' => $invite], 201);
    }

    /**
     * Public, unauthenticated endpoint — the invite is looked up by its own
     * unguessable, single-use, expiring token, so it deliberately bypasses
     * the tenant scope rather than relying on a tenant context that can't
     * exist yet (nobody is logged in at this point).
     */
    public function accept(AcceptInviteRequest $request, string $token): JsonResponse
    {
        $invite = Invite::withoutGlobalScopes()->where('signed_token', $token)->firstOrFail();

        if ($invite->used_at !== null) {
            abort(410, 'This invite has already been used.');
        }

        if ($invite->expires_at->isPast()) {
            abort(410, 'This invite has expired.');
        }

        $data = $request->validated();
        $email = $invite->email ?? $data['email'] ?? null;

        if ($email === null) {
            throw ValidationException::withMessages([
                'email' => 'An email address is required to accept this invite.',
            ]);
        }

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email already exists.',
            ]);
        }

        $user = User::create([
            'tenant_id' => $invite->tenant_id,
            'name' => $data['name'],
            'email' => $email,
            'password' => $data['password'],
            'role' => $invite->role,
        ]);

        $invite->forceFill(['used_at' => now()])->save();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => $user,
            'tenant' => $user->tenant,
        ], 201);
    }
}
