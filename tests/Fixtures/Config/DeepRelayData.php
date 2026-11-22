<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 19): `deploy` is host-only, `token` a secret one level down, `inner` holds a secret two levels
 * down and a third level ({@see DeepRelayCore}), and `extra` is an optional branch with its own secret.
 */
class DeepRelayData extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public string $deploy,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?string $token,
        public DeepRelayInner $inner,
        public DeepRelayExtra|Optional|null $extra,
    ) {}
}
