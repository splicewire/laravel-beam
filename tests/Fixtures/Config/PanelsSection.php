<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture: a section whose keys cover every operator-tier case of config-sections ticket 05. `enabled`, `banner`,
 * `title`, `api_key` and `catalog` are overridable at the operator tier and `live`; `banner` admits null and `title`
 * does not; `api_key` is a secret; `catalog` is an `extend` map; `driver` keeps the default `host` ceiling.
 */
#[ConfigRoot(root: 'panels', title: 'Panels', description: 'How panels render.')]
class PanelsSection extends ConfigSection
{
    /** @param  array<string, string>  $catalog */
    public function __construct(
        #[Title('Enabled'), Description('Whether panels render.')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public bool $enabled,
        #[Title('Banner'), Description('A banner above every panel; null shows none.')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public ?string $banner,
        #[Title('Title'), Description('The panel heading.')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public string $title,
        #[Title('API key'), Description('The panel provider key.')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live, secret: true)]
        public string|Optional|null $api_key,
        #[Title('Catalog'), Description('Named panel kinds.')]
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live, merge: ConfigMerge::Extend)]
        public array $catalog,
        #[Title('Driver')]
        public string $driver,
    ) {}
}
