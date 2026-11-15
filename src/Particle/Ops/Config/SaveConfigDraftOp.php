<?php

namespace Splicewire\Beam\Particle\Ops\Config;

use Illuminate\Http\Request;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Data\Config\ConfigSaveDraftInputData;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/**
 * `save-draft` on `beam-config` (config-sections ticket 05): one `set`, explicit `null` or `unset` on one pointer of
 * one section's operator DRAFT. Refused (422) above the key's tier ceiling, on a value the section schema rejects, or
 * on an `extend` collision; a draft changes nothing the runtime applies until it is published.
 *
 * A collection-level op (`NoSubject`): a section row is not a model, so the section is named in the input. The host
 * mounts it with `Particle::ops('beam-config', 'beam-config', [...])` ABOVE any `beam-config/{id}` route.
 */
#[ParticleOp(
    resource: 'beam-config',
    name: 'save-draft',
    kind: OperationKind::Write,
    ability: 'entitlement:os.operate',
    abilityModel: false,
    input: ConfigSaveDraftInputData::class,
    output: ConfigSectionData::class,
    subject: NoSubject::class,
    affordance: new ActionAffordance(label: 'Save config draft'),
    summary: 'Save one op on one key to a section\'s operator draft',
)]
final class SaveConfigDraftOp
{
    use RespondsWithConfigSection;

    public static function handle(mixed $model, Request $request, mixed $actor): ConfigSectionData
    {
        $input = ConfigSaveDraftInputData::validateAndCreate($request->all());
        app(ConfigOverrideStore::class)->saveDraft($input->root, [$input->op()], self::actorKey($actor));

        return self::row($input->root);
    }
}
