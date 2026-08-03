<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'status'])]
class Tenant extends Model
{
    public static function uniqueSlugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;

        for ($suffix = 2; static::where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Invite, $this> */
    public function invites(): HasMany
    {
        return $this->hasMany(Invite::class);
    }

    /** @return HasMany<Application, $this> */
    public function apps(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
