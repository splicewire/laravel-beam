<?php

namespace Splicewire\Beam\Particle\Ops\Config;

use Illuminate\Http\Request;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Data\Config\ConfigSectionRefInputData;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/** `discard-draft` on `beam-config` (config-sections ticket 05): put a section's draft back to its published version. */
#[ParticleOp(
    resource: 'beam-config',
    name: 'discard-draft',
    kind: OperationKind::Write,
    ability: 'entitlement:os.operate',
    abilityModel: false,
    input: ConfigSectionRefInputData::class,
    output: ConfigSectionData::class,
    subject: NoSubject::class,
    affordance: new ActionAffordance(label: 'Discard config draft', destructive: true),
    summary: 'Discard a section\'s operator draft',
)]
final class DiscardConfigDraftOp
{
    use RespondsWithConfigSection;

    public static function handle(mixed $model, Request $request, mixed $actor): ConfigSectionData
    {
        $input = ConfigSectionRefInputData::validateAndCreate($request->all());
        app(ConfigOverrideStore::class)->discardDraft($input->root);

        return self::row($input->root);
    }
}
