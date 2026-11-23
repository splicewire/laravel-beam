<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/** Fixture (config-sections 22): a plain holder whose `creds` object is declared secret as a whole. */
class StickyHolder extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $label,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public StickyCreds $creds,
    ) {}
}
