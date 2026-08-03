<?php

namespace App\Actions;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shared between the JSON signup endpoint (App\Http\Controllers\Api\AuthController)
 * and Fortify's registration action (App\Actions\Fortify\CreateNewUser) —
 * both create-account paths need the same "signup creates tenant + owner"
 * behavior (mvp-scope.md §6), not just a bare user.
 */
class CreateTenantAndOwner
{
    public function handle(string $tenantName, string $name, string $email, string $password): User
    {
        return DB::transaction(function () use ($tenantName, $name, $email, $password) {
            $tenant = Tenant::create([
                'name' => $tenantName,
                'slug' => Tenant::uniqueSlugFor($tenantName),
            ]);

            return User::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => 'owner',
            ]);
        });
    }
}
