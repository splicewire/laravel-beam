<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/**
 * Fixture (config-sections 22 F1, Codex review lane A): `creds` is typed by an abstract Data class whose `token` is plain,
 * and a loaded subclass ({@see DeclInheritedSecretCreds}) re-declares that inherited property secret.
 */
#[ConfigRoot(root: 'qa.inherited', title: 'Inherited secret')]
class DeclInheritedSecretSection extends ConfigSection
{
    public function __construct(
        public DeclInheritedBaseCreds $creds,
    ) {}
}
