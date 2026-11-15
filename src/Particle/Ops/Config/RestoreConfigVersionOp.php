<?php

namespace Splicewire\Beam\Particle\Ops\Config;

use Illuminate\Http\Request;
use Splicewire\Beam\Config\Overrides\ConfigOverrideRefusal;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Data\Config\ConfigRestoreInputData;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/** `restore` on `beam-config` (config-sections ticket 05): restore a version as a NEW draft; it is never a publish. */
#[ParticleOp(
    resource: 'beam-config',
    name: 'restore',
    kind: OperationKind::Write,
    ability: 'entitlement:os.operate',
    abilityModel: false,
    input: ConfigRestoreInputData::class,
    output: ConfigSectionData::class,
    subject: NoSubject::class,
    affordance: new ActionAffordance(label: 'Restore config version'),
    summary: 'Restore a version of a section\'s operator tier as a new draft',
)]
final class RestoreConfigVersionOp
{
    use RespondsWithConfigSection;

    public static function handle(mixed $model, Request $request, mixed $actor): ConfigSectionData
    {
        $input = ConfigRestoreInputData::validateAndCreate($request->all());
        $store = app(ConfigOverrideStore::class);
        $version = collect($store->versionsOf($input->root))->firstWhere('version', $input->version)
            ?? throw ConfigOverrideRefusal::because('version', "{$input->root} has no version {$input->version}.");
        $store->restore($input->root, $version->id);

        return self::row($input->root);
    }
}
