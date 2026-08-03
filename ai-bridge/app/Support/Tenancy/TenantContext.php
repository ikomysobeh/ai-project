<?php

namespace App\Support\Tenancy;

class TenantContext
{
    protected ?int $tenantId = null;

    public function id(): ?int
    {
        return $this->tenantId;
    }

    public function set(?int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function has(): bool
    {
        return $this->tenantId !== null;
    }
}
