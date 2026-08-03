<?php

namespace App\Actions\Fortify;

use App\Actions\CreateTenantAndOwner;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user (+ their tenant — this is
     * always a brand-new signup, mvp-scope.md §6: "Signup creates tenant +
     * owner").
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'tenant_name' => ['required', 'string', 'max:255'],
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return app(CreateTenantAndOwner::class)->handle(
            $input['tenant_name'],
            $input['name'],
            $input['email'],
            $input['password'],
        );
    }
}
