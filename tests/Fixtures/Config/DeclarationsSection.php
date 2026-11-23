<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 22, 21, 17): one declaration per finding the section audit reads
 * ({@see \Splicewire\Beam\Config\ConfigSectionDeclarations}), and the controls beside them.
 *
 * - `vault.token`: an explicit `secret: false` below a secret object (22);
 * - `relays[].password`: a secret inside a list of records (21);
 * - `api_key`: a secret the operator may override, with no `closed:` either way (17);
 * - `closed_key` / `opened_key`: the same secret, declared closed to the operator / explicitly open (controls).
 */
#[ConfigRoot(root: 'qa.decl', title: 'Declarations')]
class DeclarationsSection extends ConfigSection
{
    /** @param array<int, DeclRelay> $relays */
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public DeclVault $vault,
        #[DataCollectionOf(DeclRelay::class)]
        public array $relays,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public ?string $api_key,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true, closed: [ConfigTier::Operator])]
        public ?string $closed_key,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true, closed: [])]
        public ?string $opened_key,
    ) {}
}
