<?php

namespace Splicewire\Beam\Data\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/** The input of a `beam-config` op that acts on one section's operator tier as a whole (`publish`, `discard-draft`, `versions`). */
#[TypeScript, Title('Config section')]
final class ConfigSectionRefInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[Title('Section'), Description('The config root, such as beam.ux.')]
        public string $root,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['root' => ['required', 'string']];
    }
}
