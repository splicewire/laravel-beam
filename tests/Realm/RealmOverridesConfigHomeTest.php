<?php

namespace Splicewire\Beam\Tests\Realm;

use Splicewire\Beam\Realm\RealmResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The overrides key moved home to its reader (owner ruling 2026-10-18, architecture-pass A4-C5):
 * `beam.core.realm_resource_overrides`, with a fallback read of the retired
 * `frame.realm_resource_overrides` spelling so hosts that still set the old key (flagship, www)
 * stay green until their migration lands. Three worlds, each resolved through the provider's real
 * singleton — the registry hydrates lazily, so a config set in the test reaches it.
 */
class RealmOverridesConfigHomeTest extends TestCase
{
    private const OVERLAY = ['operator' => ['widgets' => ['label' => 'Widget Ops']]];

    private function hydratedHasOverride(string $key, string $realm): bool
    {
        $this->app->forgetInstance(RealmResourceRegistry::class);

        return $this->app->make(RealmResourceRegistry::class)->hasOverride($key, $realm);
    }

    public function test_the_beam_key_hydrates_the_registry(): void
    {
        config()->set('beam.core.realm_resource_overrides', self::OVERLAY);

        $this->assertTrue($this->hydratedHasOverride('widgets', 'operator'));
    }

    public function test_the_retired_frame_spelling_still_hydrates_while_unmigrated(): void
    {
        config()->set('frame.realm_resource_overrides', self::OVERLAY);

        $this->assertTrue(
            $this->hydratedHasOverride('widgets', 'operator'),
            'the fallback read keeps unmigrated hosts green',
        );
    }

    public function test_a_set_beam_key_supersedes_the_retired_spelling_entirely(): void
    {
        config()->set('beam.core.realm_resource_overrides', []);
        config()->set('frame.realm_resource_overrides', self::OVERLAY);

        $this->assertFalse(
            $this->hydratedHasOverride('widgets', 'operator'),
            'setting the beam key (even empty) retires the fallback for this host',
        );
    }
}
