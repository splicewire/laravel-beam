<?php

namespace Splicewire\Beam\Data\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;

/** The input of `beam-config`'s `lock` op (config-sections ticket 07): lock or unlock one key for the tenant tier. */
#[TypeScript, Title('Config lock')]
final class ConfigLockInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[Title('Section'), Description('The config root, such as beam.ux.')]
        public string $root,
        #[Title('Key'), Description('An RFC 6901 pointer below the root, such as /releases/review_required. A lock covers every key below it.')]
        public string $pointer,
        #[Title('Locked'), Description('True locks the key for tenants; false removes the lock.')]
        public bool $locked = true,
        #[Title('Reason'), Description('Shown to the tenant beside the locked key. Required to lock.')]
        public ?string $reason = null,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'root' => ['required', 'string'],
            'pointer' => ['required', 'string'],
            'locked' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
