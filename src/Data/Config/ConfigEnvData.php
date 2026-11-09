<?php

namespace Splicewire\Beam\Data\Config;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The env var that feeds a key (ADR-0245): what "deploy-only" tells an operator to change.
 *
 * `set` is THREE-state, because "the process cannot see it" and "the config was built without it" are different
 * facts under `config:cache`:
 *
 * - `true`: the running process sees the variable;
 * - `false`: config is NOT cached and the variable is absent from the process and `.env`, so the key runs on its
 *   file default;
 * - `null`: unknown. Config is cached, Laravel no longer loads `.env`, and the process does not see the variable,
 *   so whether the cached value came from it or from the file default cannot be read back. Never shown as "file
 *   default".
 *
 * `file`/`line` are null for a binding declared on the key (`#[ConfigKey(env: …)]`) rather than found in a file.
 */
#[TypeScript]
class ConfigEnvData extends BeamData
{
    public function __construct(
        public string $name,
        public ?bool $set,
        public ?string $file,
        public ?int $line,
    ) {}
}
