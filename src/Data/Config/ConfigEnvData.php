<?php

namespace Splicewire\Beam\Data\Config;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The env var that feeds a key (ADR-0242): what "deploy-only" tells an operator to change. `set` says whether the
 * running process has the variable at all; a key bound to an unset variable runs on its file default.
 * `file`/`line` are null for a binding declared on the key (`#[ConfigKey(env: …)]`) rather than found in a file.
 */
#[TypeScript]
class ConfigEnvData extends BeamData
{
    public function __construct(
        public string $name,
        public bool $set,
        public ?string $file,
        public ?int $line,
    ) {}
}
