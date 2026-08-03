<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every tenant-owned model uses this so no query can forget the tenant_id
 * filter — the #1 defense against cross-tenant data leaks (AI-BUILD-BRIEF.md
 * §10). Pairs with TenantScope, which fails closed when no tenant is set.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->tenant_id === null && app(TenantContext::class)->has()) {
                $model->tenant_id = app(TenantContext::class)->id();
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
