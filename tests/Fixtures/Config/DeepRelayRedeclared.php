<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixtures (config-sections 19): `qa.deep` declared again after a deploy, for the rule "a kept child that is no longer
 * declared secret, or no longer declared, does not linger". Loaded by `class_exists(DeepRelayRedeclared::class)`; the
 * classes below share this file because only that test reads them.
 */
final class DeepRelayRedeclared {}

/** `token` is still declared, but no longer secret. */
#[ConfigRoot(root: 'qa.deep', title: 'Deep relay')]
class DeepRelayPlainTokenSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public DeepRelayPlainTokenData $relay,
    ) {}
}

class DeepRelayPlainTokenData extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public string $deploy,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string|Optional|null $token,
        public DeepRelayInner $inner,
        public DeepRelayExtra|Optional|null $extra,
    ) {}
}

/** `token` is no longer declared at all. */
#[ConfigRoot(root: 'qa.deep', title: 'Deep relay')]
class DeepRelayNoTokenSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public DeepRelayNoTokenData $relay,
    ) {}
}

class DeepRelayNoTokenData extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public string $deploy,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $host,
        public DeepRelayInner $inner,
        public DeepRelayExtra|Optional|null $extra,
    ) {}
}

/** `token` is still secret, but its ceiling NARROWED to host (Codex T19-R2b: a kept child must still be admitted). */
#[ConfigRoot(root: 'qa.deep', title: 'Deep relay')]
class DeepRelayHostTokenSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public DeepRelayHostTokenData $relay,
    ) {}
}

class DeepRelayHostTokenData extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public string $deploy,
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Host, secret: true)]
        public ?string $token,
        public DeepRelayInner $inner,
        public DeepRelayExtra|Optional|null $extra,
    ) {}
}
