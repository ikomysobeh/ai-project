<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateTenantAndOwner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\SignupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function signup(SignupRequest $request, CreateTenantAndOwner $createTenantAndOwner): JsonResponse
    {
        $data = $request->validated();

        $user = $createTenantAndOwner->handle(
            $data['tenant_name'],
            $data['name'],
            $data['email'],
            $data['password'],
        );

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => $user,
            'tenant' => $user->tenant,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt($request->only('email', 'password'), true)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user()->load('tenant');

        return response()->json([
            'user' => $user,
            'tenant' => $user->tenant,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(status: 204);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('tenant');

        return response()->json([
            'user' => $user,
            'tenant' => $user->tenant,
        ]);
    }
}
