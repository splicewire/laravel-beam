<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigEffect;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Config\ConfigTier;

/** A record-shaped fixture section with a package file, a host override and one secret. */
#[ConfigRoot(root: 'widgets', title: 'Widgets', description: 'How widgets render.')]
class WidgetsSection extends ConfigSection
{
    public function __construct(
        #[Title('Enabled'), Description('Whether widgets render at all.')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public bool $enabled,
        public WidgetLimits $limits,
        public string $driver,
        #[Title('API key'), ConfigKey(secret: true)]
        public string|Optional|null $api_key,
    ) {}
}
