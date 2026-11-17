<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * Fixture (config-sections ticket 07): core's tenancy port answered by hand, so core can test the tenant tier with no
 * tenancy library. {@see enter()} does what a tenancy bootstrapper does first: the DEFAULT connection becomes the
 * tenant's own database (`tenant_<id>`); {@see leave()} puts it back. beam-tenancy proves the same behaviour with stancl's
 * real bootstrappers on schema, pooled and isolated tenants.
 */
class FakeTenancyContext implements TenancyContext
{
    public ?string $tenant = null;

    public function enter(string $tenant): void
    {
        $this->tenant = $tenant;
        DB::setDefaultConnection("tenant_{$tenant}");
    }

    public function leave(): void
    {
        $this->tenant = null;
        DB::setDefaultConnection('testing');
    }

    public function isMultiTenant(): bool
    {
        return true;
    }

    public function isActive(): bool
    {
        return $this->tenant !== null;
    }

    public function currentTenant(): ?object
    {
        return $this->tenant === null ? null : (object) ['id' => $this->tenant];
    }

    public function currentTenantId(): int|string|null
    {
        return $this->tenant;
    }

    public function centralConnection(): ?string
    {
        return 'central';
    }
}
