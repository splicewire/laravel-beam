<?php

namespace Splicewire\Beam\Particle\Ops\Config;

use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\Overrides\ConfigOverrideScope;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Data\Config\ConfigSectionRefInputData;
use Splicewire\Beam\Data\Config\ConfigVersionData;
use Splicewire\Beam\Data\Config\ConfigVersionsData;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;

/** `versions` on `beam-config` (config-sections ticket 05): a section's operator-tier history, newest first. */
#[ParticleOp(
    resource: 'beam-config',
    name: 'versions',
    kind: OperationKind::Read,
    ability: ConfigSectionAccess::OPERATE,
    abilityModel: ConfigOverrideScope::class,
    input: ConfigSectionRefInputData::class,
    output: ConfigVersionsData::class,
    subject: NoSubject::class,
    summary: 'List a section\'s operator-tier versions',
)]
final class ConfigVersionsOp
{
    public static function handle(mixed $model, Request $request, mixed $actor): ConfigVersionsData
    {
        $input = ConfigSectionRefInputData::validateAndCreate($request->all());
        $store = app(ConfigOverrideStore::class);
        $scope = $store->scope($input->root);
        $versions = [];

        foreach ($store->versionsOf($input->root) as $version) {
            // Version keeps Eloquent's timestamps but declares no @property for them.
            $createdAt = $version->getAttributeValue($version->getCreatedAtColumn());
            $versions[] = new ConfigVersionData(
                id: $version->id,
                version: $version->version,
                label: $version->label,
                createdBy: $version->created_by,
                createdAt: $createdAt instanceof CarbonInterface ? $createdAt->toIso8601String() : null,
                draft: $scope?->head_version === $version->id,
                published: $scope?->published_version === $version->id,
            );
        }

        return new ConfigVersionsData(root: $input->root, versions: $versions);
    }
}
