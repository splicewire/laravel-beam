<?php

namespace Splicewire\Beam\Tests\Registries;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;
use Splicewire\Beam\Capabilities\CapabilityRegistry;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Realm\RealmOverlayRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Realm\RealmResourceRegistry;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;
use Splicewire\Beam\Schema\SchemaSources;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Surface\GroupRegistry;
use Splicewire\Beam\Surgeon\AuditScanPaths;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * beam-core's registries declare themselves with `#[IsRegistry]` (registry-kernel ticket 21).
 *
 * ## What this replaces, and why it is a different assertion
 *
 * The predecessor — `ManifestIndexTest` — asserted that beam pushed `ManifestDescriptor`s into a
 * `ManifestIndex` it owned, and matched them by short class NAME. Ticket 21 deleted that whole vocabulary:
 * a registry now declares itself on the class, and gets INDEXED separately once it conforms to the
 * `Registry` contract. Those are two acts on purpose, because declaration lands ahead of conformance across
 * the estate's migration (ticket 14 D10's dispositions exist for exactly that gap), so this file tests the
 * half that is true today and does not pretend to test the other.
 *
 * The name match is what made the old assertion weak: three estate classes were called `CapabilityRegistry`
 * (two still are; the third is `Tower\Circuit\Capabilities\CapabilityLadder` since registry-kernel ticket 44),
 * so one descriptor satisfied all three. Roots cannot collide silently — {@see test_no_two_beam_registries_declare_the_same_root}
 * is the check the old suite had no way to write.
 */
class BeamRegistryDeclarationTest extends TestCase
{
    /**
     * Every beam-core registry, and the root it owns.
     *
     * Written out rather than discovered by a scan: a scan would pass by finding nothing if the attribute
     * were dropped wholesale, which is the one regression this file exists to catch.
     *
     * @return array<class-string, string>
     */
    public static function declaredRegistries(): array
    {
        return [
            BeamInstallManifest::class => 'beam.install.steps',
            BeamDoctorManifest::class => 'beam.doctor.audits',
            BeamSeedManifest::class => 'beam.seed.steps',
            SchemaSources::class => 'schemas.sources',
            AuditScanPaths::class => 'beam.surgeon.scan-paths',
            FacadeConformanceScope::class => 'beam.doctor.facade-scope',
            ParticleResourceRegistry::class => 'beam.particle.resources',
            ParticleOperationRegistry::class => 'beam.particle.operations',
            GroupRegistry::class => 'beam.surface.groups',
            RealmRegistry::class => 'beam.realm',
            RealmOverlayRegistry::class => 'beam.realm.overlays',
            RealmResourceRegistry::class => 'beam.realm.resource-overrides',
            CapabilityRegistry::class => 'beam.capabilities',
            ResourceRenderingRegistry::class => 'beam.renderings',
        ];
    }

    public function test_every_beam_registry_declares_a_legal_root(): void
    {
        foreach (self::declaredRegistries() as $class => $expected) {
            $declaration = IsRegistry::of($class);

            $this->assertNotNull($declaration, "{$class} declares no #[IsRegistry]");
            $this->assertSame($expected, $declaration->root, "{$class} declares an unexpected root");

            // Parses, and therefore obeys ticket 05's charset: lowercase-kebab segments, dot-separated,
            // `:` and `/` rejected rather than folded. A root that does not parse is unroutable.
            $this->assertSame($expected, (string) Key::parse($declaration->root));

            $this->assertNotSame('', $declaration->of, "{$class} must say what it is a registry OF");
        }
    }

    public function test_no_two_beam_registries_declare_the_same_root(): void
    {
        $roots = array_values(self::declaredRegistries());

        $this->assertSame(
            count($roots),
            count(array_unique($roots)),
            'Two registries on one root make that branch unroutable — the index refuses it at describe time '
                .'(OnDuplicate::Reject), so a duplicate here is a boot failure waiting to happen.',
        );
    }

    public function test_roots_may_nest_and_are_compared_segment_wise(): void
    {
        // `beam.realm.overlays` sits UNDER `beam.realm` deliberately; longest-prefix routing tells them
        // apart. The trap this guards is character-wise thinking: `beam.realms` would NOT be under
        // `beam.realm`, however much the strings suggest otherwise.
        $this->assertTrue(Key::parse('beam.realm.overlays')->isUnder(Key::parse('beam.realm')));
        $this->assertFalse(Key::parse('beam.realms')->isUnder(Key::parse('beam.realm')));
    }

    public function test_arity_is_declared_and_is_not_a_function_of_the_class_name_suffix(): void
    {
        // The axis the Registry/Manifest naming split was really tracking (canon: the-seam-is-a-registry):
        // both are one primitive, and arity is what differs. Two classes suffixed `Registry` here disagree
        // about arity, and a `Manifest` and a `Registry` agree — which is the whole point.
        $this->assertSame([RegistryArity::PickOne], IsRegistry::of(RealmRegistry::class)?->arity);
        $this->assertSame([RegistryArity::ComposeMany], IsRegistry::of(RealmOverlayRegistry::class)?->arity);
        $this->assertSame([RegistryArity::RunAll], IsRegistry::of(BeamInstallManifest::class)?->arity);
        // Two steps, outermost first: PickOne selects a resource, RunAll engages that resource's
        // renderings. The bare RunAll it used to declare was ticket 47's second beneficiary.
        $this->assertSame(
            [RegistryArity::PickOne, RegistryArity::RunAll],
            IsRegistry::of(ResourceRenderingRegistry::class)?->arity,
        );
    }

    public function test_a_non_default_duplicate_policy_is_declared_rather_than_inherited(): void
    {
        // The estate ships all three policies with argued docblocks, so a kernel that picked one would
        // break the other two (ticket 06 D2). Where beam's behaviour is NOT the default, it says so.
        $this->assertSame(OnDuplicate::Admit, IsRegistry::of(RealmOverlayRegistry::class)?->onDuplicate);
        $this->assertSame(OnDuplicate::Admit, IsRegistry::of(AuditScanPaths::class)?->onDuplicate);

        // And where it IS the default it still says so, because both docblocks argue overwrite is
        // intentional — a claim worth making in the attribute rather than by omission.
        $this->assertSame(OnDuplicate::Supersede, IsRegistry::of(ParticleResourceRegistry::class)?->onDuplicate);
    }

    public function test_the_two_particle_pipelines_are_deliberately_undeclared(): void
    {
        // Undescribed by ticket 07 D5: both are `bind()` rather than singletons, neither has `register()`,
        // and their `$stages` list is constructor-seeded — a composition seam, not a keyspace. Asserted so
        // that a future reader looking at two pipeline-shaped classes does not helpfully re-add them.
        $this->assertNull(IsRegistry::of(ParticleWriter::class));
        $this->assertNull(IsRegistry::of(PayloadParticleReader::class));
    }
}
