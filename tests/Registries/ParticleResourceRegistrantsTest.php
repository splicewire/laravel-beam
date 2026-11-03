<?php

namespace Splicewire\Beam\Tests\Registries;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\RecordsRegistrants;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Registrar\ScannedPaperModel;
use Splicewire\Beam\Tests\TestCase;

/**
 * `ParticleResourceRegistry` implements `RecordsRegistrants` (registry-docs ticket 03, the optional
 * scope of ticket 01's amended ruling 6): the `$by` every registration already writes into the
 * composed store is readable back, filtered — the registry docs' registrant panel reads it.
 */
class ParticleResourceRegistrantsTest extends TestCase
{
    private function resource(string $key): ParticleResource
    {
        return new ParticleResource(key: $key, backing: ScannedPaperModel::class);
    }

    public function test_the_registrant_a_registration_named_is_read_back(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register($this->resource('papers'), by: 'vendor/papers-package');
        $registry->register($this->resource('notes'), by: 'vendor/papers-package');
        $registry->register($this->resource('anonymous'));

        $this->assertInstanceOf(RecordsRegistrants::class, $registry);
        $this->assertSame('vendor/papers-package', $registry->registrantOf('papers'));
        $this->assertNull($registry->registrantOf('anonymous'), 'a registration that named nobody answers null');
        $this->assertNull($registry->registrantOf('absent'));
        $this->assertNull($registry->registrantOf('Not A Key'), 'a malformed key is a miss, never a throw');
        $this->assertSame(
            ['beam.particle.resources.papers', 'beam.particle.resources.notes'],
            array_map('strval', $registry->keysBy('vendor/papers-package')),
        );
    }

    public function test_a_hidden_declaration_answers_exactly_like_an_absent_one(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register($this->resource('open'), by: 'vendor/open');
        $registry->register('secret', $this->resource('secret'), by: 'vendor/secret', ability: 'see-secret');
        $registry->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });

        $this->assertNull($registry->registrantOf('secret'));
        $this->assertSame([], $registry->keysBy('vendor/secret'));
        $this->assertSame('vendor/open', $registry->registrantOf('open'));
        $this->assertSame('vendor/secret', $registry->unfiltered()->registrantOf('secret'), 'tooling reads through unfiltered()');
    }
}
