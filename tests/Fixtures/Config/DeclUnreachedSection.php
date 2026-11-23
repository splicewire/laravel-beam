<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 22, F1): `transport` is typed by an INTERFACE the declaration walk does not descend, and an
 * implementation of it ({@see DeclSmtpTransport}) declares a secret the walk cannot see. `sealed` is the same type
 * declared secret as a whole (the control: split whole, nothing below it is plain).
 */
#[ConfigRoot(root: 'qa.unreached', title: 'Unreached')]
class DeclUnreachedSection extends ConfigSection
{
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Host)]
        public DeclTransport $transport,
        #[ConfigKey(ceiling: ConfigTier::Host, secret: true)]
        public DeclTransport $sealed,
    ) {}
}
