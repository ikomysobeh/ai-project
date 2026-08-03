<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property array{psid: string, psidts: string} $cookies_encrypted */
#[Fillable(['user_id', 'label', 'cookies_encrypted', 'status'])]
#[Hidden(['cookies_encrypted'])]
class UpstreamAccount extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            // Laravel's `encrypted:array` cast — cookies must never be stored
            // or logged in plaintext (AI-BUILD-BRIEF.md §10.4). Holds
            // ['psid' => ..., 'psidts' => ...].
            'cookies_encrypted' => 'encrypted:array',
            'last_used_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'health_checked_at' => 'datetime',
        ];
    }

    public function markHealthy(): void
    {
        $this->forceFill([
            'status' => 'active',
            'error_count' => 0,
            'health_checked_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function markExpired(?string $reason = null): void
    {
        $this->forceFill([
            'status' => 'expired',
            'error_count' => $this->error_count + 1,
            'health_checked_at' => now(),
            'last_error' => $reason,
        ])->save();
    }

    public function markCoolingDown(): void
    {
        $this->forceFill([
            'status' => 'cooling_down',
            'cooldown_until' => now()->addMinutes(5),
            'error_count' => $this->error_count + 1,
            'health_checked_at' => now(),
        ])->save();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }
}
