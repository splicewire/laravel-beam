<?php

namespace Splicewire\Beam\Particle\Ops\Config;

use Illuminate\Http\Request;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Data\Config\ConfigSectionRefInputData;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/**
 * `publish` on `beam-config` (config-sections ticket 05): move a section's published pin to its saved draft, directly,
 * with no Release (a Release joins in ticket 08). The published-version counter moves, so the next request, job and
 * command apply it with no deploy and no restart.
 */
#[ParticleOp(
    resource: 'beam-config',
    name: 'publish',
    kind: OperationKind::Write,
    ability: ConfigSectionAccess::OPERATE,
    abilityModel: ConfigOverrideScope::class,
    input: ConfigSectionRefInputData::class,
    output: ConfigSectionData::class,
    subject: NoSubject::class,
    affordance: new ActionAffordance(label: 'Publish config'),
    summary: 'Publish a section\'s operator draft',
)]
final class PublishConfigOp
{
    use RespondsWithConfigSection;

    public static function handle(mixed $model, Request $request, mixed $actor): ConfigSectionData
    {
        $input = ConfigSectionRefInputData::validateAndCreate($request->all());
        app(ConfigOverrideStore::class)->publish($input->root);

        return self::row($input->root);
    }
}
