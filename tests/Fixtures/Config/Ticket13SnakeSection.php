<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Config\ConfigSection;

/**
 * Fixture (config-sections ticket 13, Part 2, condition 2 — the class-level mapper case). A section whose CLASS carries
 * a Spatie name mapper: its camelCase PHP properties are keyed on the wire in snake_case. Only Spatie knows that a
 * class-level mapper is in force — a hand-rolled attribute reader cannot see it — so coverage, the schema and the
 * emitter must all ask Spatie for the wire name ({@see \Splicewire\Beam\Config\ConfigWireNames}). The test proves the
 * schema's `x-beam-config` lands on the snake_case key and coverage of the same snake_case config agrees.
 *
 * `reviewRequired` is required (non-nullable, no default); `editAbility` is nullable and defaulted, so Part 4 prunes its
 * wire name `edit_ability` from `required`.
 */
#[ConfigRoot(root: 'cs13.snake', title: 'Snake-mapped section')]
#[MapName(SnakeCaseMapper::class)]
class Ticket13SnakeSection extends ConfigSection
{
    public function __construct(
        #[Title('Review required'), Description('Whether a release waits on review.'), ConfigKey]
        public bool $reviewRequired,
        #[Title('Edit ability'), Description('Who may edit, or null for nobody.'), ConfigKey]
        public ?string $editAbility = null,
    ) {}
}
