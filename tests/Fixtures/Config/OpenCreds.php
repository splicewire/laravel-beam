<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 17): {@see ClosedCreds} before `account` was closed, when it was also OPTIONAL, so a stored
 * parent row could leave it out (Codex review, lane A).
 */
class OpenCreds extends Data
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public string|Optional $account,
        #[ConfigKey(ceiling: ConfigTier::Tenant)]
        public string $label,
    ) {}
}
