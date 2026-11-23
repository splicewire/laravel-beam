<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Throwable;

/**
 * **No published override silently stops applying** (config-sections 21, lead LANE-A-0420). A list is written whole
 * now, so a row stored before the ticket through a list INDEX (`/relays/0/host`) is dropped from the published layer:
 * it would land on whatever record sits at that index after the list changes. Dropping it is right; dropping it
 * without a word is not, because the operator saved and published that value. This audit counts those rows per section
 * and tier and warns, naming them ({@see ConfigOverrideStore::storedListRows()}).
 *
 * Read-only: it repairs nothing. The fix is the operator's: re-save the list whole and publish. The tiers checked are
 * the operator's (central), and the tenant's while a tenant is initialized. A central run does not open tenant stores,
 * and every finding it returns says so ({@see self::TENANTS_UNCHECKED}), naming the per-tenant run. Report-only
 * (advisory).
 */
class ConfigStoredListRowAudit implements DoctorAudit
{
    public const CHECK = 'config.stored-list-rows';

    /** What a central run appends: it never opens a tenant's store, and how to run the check per tenant. */
    public const TENANTS_UNCHECKED = 'Tenant stores were NOT checked: a central run reads only the operator tier. To check each tenant, run the doctor inside it, e.g. `php artisan tenants:run splicewire:beam:doctor` (stancl/tenancy, as laravel-beam-tenancy installs it; `--tenants=<id>` for one).';

    private const SHOWN = 5;

    public function __construct(
        protected ConfigOverrideStore $store,
        protected TenancyContext $tenancy,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $tiers = [ConfigTier::Operator->value => ConfigTier::Operator];

        if ($this->tenancy->isActive()) {
            $tiers['tenant '.$this->tenancy->currentTenantId()] = ConfigTier::Tenant;
        }

        $findings = [];

        foreach ($tiers as $label => $tier) {
            try {
                foreach ($this->store->forTier($tier)->storedListRows() as $root => $pointers) {
                    $findings[] = Finding::warn(self::CHECK, sprintf(
                        '%s (%s tier): %d published override row(s) were written through a list index and no longer apply, because a list is written whole (config-sections 21): %s. Re-save the list whole and publish; the doctor does not repair them.',
                        $root,
                        $label,
                        count($pointers),
                        $this->listed($pointers),
                    ));
                }
            } catch (Throwable $e) {
                $findings[] = Finding::warn(self::CHECK, sprintf('The %s tier could not be checked: %s', $label, $e->getMessage()));
            }
        }

        $findings = $findings !== [] ? $findings : [Finding::pass(self::CHECK, sprintf(
            'No published override row is written through a list index (tiers checked: %s).',
            implode(', ', array_keys($tiers)),
        ))];

        if ($this->tenancy->isActive()) {
            return $findings;
        }

        // A central run reads only central (lead LANE-A-0430): every finding says so, and names how to check the tenants.
        return array_map(
            fn (Finding $finding): Finding => new Finding($finding->status, $finding->check, $finding->detail.' '.self::TENANTS_UNCHECKED, $finding->conclusive),
            $findings,
        );
    }

    /** @param list<string> $pointers */
    private function listed(array $pointers): string
    {
        $shown = implode(', ', array_slice($pointers, 0, self::SHOWN));

        return count($pointers) > self::SHOWN ? $shown.sprintf(' (+%d more)', count($pointers) - self::SHOWN) : $shown;
    }
}
