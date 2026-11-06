<?php

namespace Splicewire\Beam\Tests\Registries;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\RecordsRegistrants;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Schema\SchemaSources;
use Splicewire\Beam\Source\RouteManifestSourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * Every registry Beam owns answers `RecordsRegistrants`, so the registry docs' registrant panel can name
 * who registered each entry. Each one forwards to the `BasicRegistry` it composes, which already records
 * the `$by` of every registration and filters the read like `has()`.
 *
 * The one exception is the config-backed {@see RouteManifestSourceRegistry}. Its store is a config array
 * it does not own, and {@see RecordsRegistrants} names {@see ConfigRegistry} as the registry that
 * legitimately does not implement it.
 */
class BeamRegistriesRecordRegistrantsTest extends TestCase
{
    /** Beam-owned index roots whose owner is exempt, with the reason. */
    private const EXEMPT = [
        RouteManifestSourceRegistry::class => 'a ConfigRegistry: its store is config it does not own',
    ];

    /** The number of Beam-owned roots this harness describes, minus the exemptions. */
    private const COVERED = 18;

    /** @return array<string, object> root => owner, for every root whose owner is a Beam class */
    private function beamOwners(): array
    {
        $index = app(RegistryIndex::class);
        $owners = [];

        foreach ($index->keys() as $root) {
            $owner = $index->owner($root);

            if (is_object($owner) && str_starts_with($owner::class, 'Splicewire\\Beam\\')) {
                $owners[(string) $root] = $owner;
            }
        }

        return $owners;
    }

    public function test_every_beam_owned_root_records_its_registrants_except_the_named_exemptions(): void
    {
        $covered = [];
        $uncovered = [];

        foreach ($this->beamOwners() as $root => $owner) {
            if ($owner instanceof RecordsRegistrants) {
                $covered[] = $root;
            } else {
                $uncovered[$root] = $owner::class;
            }
        }

        $this->assertSame([], array_diff($uncovered, array_keys(self::EXEMPT)), 'an uncovered Beam root that is not a named exemption');
        $this->assertCount(self::COVERED, $covered, 'the coverage count, so a harness that describes nothing cannot pass');
    }

    public function test_each_owner_answers_what_its_store_recorded_and_never_throws_on_a_bad_key(): void
    {
        $attributed = 0;

        foreach ($this->beamOwners() as $root => $owner) {
            if (! $owner instanceof RecordsRegistrants || ! $owner instanceof Registry) {
                continue;
            }

            $store = $owner->unfiltered();
            $this->assertInstanceOf(RecordsRegistrants::class, $store, "{$root}: unfiltered() should expose the recording store");

            foreach ($owner->keys() as $key) {
                $registrant = $owner->registrantOf($key);
                $this->assertSame($store->registrantOf($key), $registrant, "{$root}: {$key}");

                if ($registrant !== null) {
                    $attributed++;
                    $this->assertContains((string) $key, array_map('strval', $owner->keysBy($registrant)), "{$root}: keysBy() is registrantOf()'s twin");
                }
            }

            $this->assertNull($owner->registrantOf('Not A Key!'), "{$root}: a malformed key is a miss, never a throw");
        }

        $this->assertGreaterThan(0, $attributed, 'at least one entry in the booted harness names its registrant');
    }

    public function test_a_hidden_entry_answers_exactly_like_an_absent_one(): void
    {
        $sources = new SchemaSources;
        $sources->register('open', fn () => [], by: 'vendor/open');
        $sources->register('secret', fn () => [], by: 'vendor/secret', ability: 'see-secret');
        $sources->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });

        $this->assertSame('vendor/open', $sources->registrantOf('open'));
        $this->assertNull($sources->registrantOf('secret'));
        $this->assertNull($sources->registrantOf('absent'));
        $this->assertSame([], $sources->keysBy('vendor/secret'));
        $this->assertSame('vendor/secret', $sources->unfiltered()->registrantOf('secret'), 'tooling reads through unfiltered()');
    }
}
