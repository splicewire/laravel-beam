<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;

/** Fixture (Codex review lane A): re-declares the inherited `token` as a secret. */
class DeclInheritedSecretCreds extends DeclInheritedBaseCreds
{
    public function __construct(
        #[ConfigKey(secret: true)]
        public ?string $token,
    ) {}
}
