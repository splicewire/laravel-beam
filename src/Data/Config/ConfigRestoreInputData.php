<?php

namespace Splicewire\Beam\Data\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/** The `beam-config` `restore` op's input: the version to restore as a NEW draft (config-sections ticket 05). */
#[TypeScript, Title('Restore a config version')]
final class ConfigRestoreInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[Title('Section'), Description('The config root, such as beam.ux.')]
        public string $root,
        #[Title('Version'), Description('The version number to restore as a new draft.')]
        public int $version,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['root' => ['required', 'string'], 'version' => ['required', 'integer', 'min:1']];
    }
}
