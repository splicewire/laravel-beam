<?php

namespace Splicewire\Beam\Particle\Ops\Config;

use Illuminate\Http\Request;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Data\Config\ConfigLockInputData;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/**
 * `lock` on `beam-config` (config-sections ticket 07; PRD "lock (operator only)"): the operator locks a key for the tenant
 * tier on this install, with the reason a tenant is shown, or removes the lock. Operator locks are central data. Refused
 * from inside a tenant: a tenant cannot lock or unlock anything ({@see ConfigOverrideStore::lock()}).
 */
#[ParticleOp(
    resource: 'beam-config',
    name: 'lock',
    kind: OperationKind::Write,
    ability: ConfigSectionAccess::OPERATE,
    abilityModel: ConfigOverrideScope::class,
    input: ConfigLockInputData::class,
    output: ConfigSectionData::class,
    subject: NoSubject::class,
    affordance: new ActionAffordance(label: 'Lock config key'),
    summary: 'Lock or unlock a config key for tenants (operator only)',
)]
final class LockConfigKeyOp
{
    use RespondsWithConfigSection;

    public static function handle(mixed $model, Request $request, mixed $actor): ConfigSectionData
    {
        $input = ConfigLockInputData::validateAndCreate($request->all());
        $store = app(ConfigOverrideStore::class);

        // The realm decides: inside a tenant the store's tier is the tenant's, and lock() refuses it.
        if ($input->locked) {
            $store->lock($input->root, $input->pointer, (string) $input->reason, self::actorKey($actor));
        } else {
            $store->unlock($input->root, $input->pointer);
        }

        return self::row($input->root);
    }
}
