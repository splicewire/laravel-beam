<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (build-qa's OperatorBoundaryQaTest shape, config-sections 05 Standards review): an operator-overridable
 * object whose children declare their OWN ceiling and secrecy. `deploy` is host-only, `token` is a secret, `label` is
 * an ordinary operator key.
 */
class NestedGroupData extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public string $deploy,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string $token,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $label,
    ) {}
}
