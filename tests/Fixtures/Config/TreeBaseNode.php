<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20): a node typed by SYMBOLIC names. `child` is `?self` (resolved against this class); `pin` is
 * a secret {@see TreeChildNode} inherits, and which its `?parent` property reaches.
 */
class TreeBaseNode extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $name,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?string $pin,
        public ?self $child,
    ) {}
}
