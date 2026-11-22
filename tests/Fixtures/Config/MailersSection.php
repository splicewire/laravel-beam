<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20): a declared secret in the ENTRY class of a map of records (`#[MapValues]`, the shape the
 * generator emits for `mail.mailers.smtp`), of a list of records (`#[DataCollectionOf]`), and of a map nested in a union.
 */
#[ConfigRoot(root: 'qa.mailers', title: 'Mailers')]
class MailersSection extends ConfigSection
{
    /**
     * @param  array<string, MailerEntry>  $mailers
     * @param  array<int, MailerEntry>  $relays
     */
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live), MapValues(MailerEntry::class)]
        public array $mailers,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live), DataCollectionOf(MailerEntry::class)]
        public array $relays,
        #[ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Live)]
        public MailerGroup|Optional $group,
    ) {}
}
