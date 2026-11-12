<?php

namespace Splicewire\Beam\Localization\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Localization\CodedValidationException;

/**
 * The 422 body of a coded refusal (localization ticket 13): Laravel's own `{message, errors}`, byte for byte as a
 * client already reads it, plus `codes`, the stable machine code beside each message. Declared, on the
 * {@see \Splicewire\Beam\Intake\Data\PublicIntakeErrorData} precedent, because a client programs against the codes and
 * cannot guess them from the success shape. Rendered by {@see CodedValidationException} for a request that expects
 * JSON.
 */
#[TypeScript]
class CodedValidationErrorData extends BeamData
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, list<string>>  $codes
     */
    public function __construct(
        #[Description('A human-readable summary of the refusal, in the request\'s language.')]
        public string $message,

        #[LiteralTypeScriptType('Record<string, string[]>')]
        #[Description('Messages keyed by input field, in the request\'s language, exactly as Laravel\'s validation response carries them.')]
        public array $errors,

        #[LiteralTypeScriptType('Record<string, string[]>')]
        #[Description('The stable code of each message in `errors`, at the same field and position. Codes never change and never depend on the language: match these, not the prose.')]
        public array $codes,
    ) {}
}
