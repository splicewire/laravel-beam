<?php

namespace Splicewire\Beam\Localization\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * `beam.localization` as a Config Section (ADR-0245; localization 06, ruling 03). Every key is Host-tier with a Deploy
 * effect: which languages a host ships, its fallback, the pseudo-locale opt-in, the brand's default language and the
 * localized HTTP profile are deploy decisions. There is no operator or tenant tier here; a workspace's default
 * language lives in the tenant's own data (ticket 36), not on this ladder.
 */
#[ConfigRoot(root: 'beam.localization', title: 'Localization', description: 'Which languages this host ships, and how a request\'s language is resolved.')]
class LocalizationSection extends ConfigSection
{
    /**
     * @param  list<string>|null  $supported
     */
    public function __construct(
        #[Title('Supported languages'), Description('Canonical locale tags (es-MX) this host ships. Negotiation only ever chooses one of these. Null: app.locale alone.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public ?array $supported,

        #[Title('Fallback language'), Description('The last rung of the locale chain, and the default when app.locale is not supported.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public string $fallback,

        #[Title('Pseudo-locale'), Description('Adds en-XA, derived from English, for development and proof. Never chosen from a wildcard or as a default.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public bool $pseudo,

        #[Title('Brand default language'), Description('The brand\'s default language (a config-backed server source). Null when the brand states none.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public ?string $brand_default,

        #[Title('Localized HTTP profile'), Description('Opt in to Accept-Language negotiation, Content-Language, Vary: Accept-Language, and private personalized responses.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public bool $http_profile,

        #[Title('Throw on a contested namespace'), Description('Whether two packages that shorten to one translator namespace throw where the host is built. Null: throw outside production.')]
        #[ConfigKey(ceiling: ConfigTier::Host, effect: ConfigEffect::Deploy)]
        public ?bool $throw_on_contested_namespace,
    ) {}
}
