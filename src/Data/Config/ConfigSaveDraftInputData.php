<?php

namespace Splicewire\Beam\Data\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Enums\ConfigOverrideOp;

/**
 * The `beam-config` `save-draft` op's input (config-sections ticket 05): one op on one pointer of one section's
 * operator draft. `value` is JSON text, so a generic form writes any type the section schema declares (`true`, `30`,
 * `"text"`, `{"a": 1}`); it is required for `set` and ignored for `null` and `unset`.
 */
#[TypeScript, Title('Save a config draft')]
final class ConfigSaveDraftInputData extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(
        #[Title('Section'), Description('The config root, such as beam.ux.')]
        public string $root,
        #[Title('Key'), Description('The key as a JSON pointer below the root, such as /releases/review_required.')]
        public string $pointer,
        #[Title('Op'), Description('Set writes the value; Set to null keeps an explicit null; Inherit removes this tier\'s value so the one below applies.')]
        public ConfigOverrideOp $op,
        #[Title('Value'), Description('For Set: the value as JSON, such as true, 30 or "text".')]
        public ?string $value = null,
    ) {}

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'root' => ['required', 'string'],
            'pointer' => ['required', 'string', 'starts_with:/'],
            'value' => ['nullable', 'required_if:op,set', 'json'],
        ];
    }

    /** @return array{pointer: string, op: ConfigOverrideOp, value: mixed} the op as the store takes it */
    public function op(): array
    {
        return [
            'pointer' => $this->pointer,
            'op' => $this->op,
            'value' => $this->op === ConfigOverrideOp::Set && $this->value !== null ? json_decode($this->value, true) : null,
        ];
    }
}
