<?php

namespace Splicewire\Beam\Localization\Client\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One resolved client line (localization 16; ruling 02 §2 and §4): its text, and the locale whose rung ANSWERED, never
 * the requested UI locale. The client selects a plural with `sourceLocale` and formats display values with the
 * catalog's requested `locale`.
 */
#[TypeScript]
final class ClientMessageLineData extends Data
{
    public function __construct(
        public string $text,
        public string $sourceLocale,
    ) {}
}
