<?php

namespace Splicewire\Beam\Tests\Authorization;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Capabilities\CapabilityRegistry;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Realm\RealmOverlayRegistry;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Realm\RealmResourceRegistry;
use Splicewire\Beam\Schema\SchemaSources;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Surface\GroupRegistry;
use Splicewire\Beam\Surgeon\AuditScanPaths;
use Splicewire\Beam\Tests\TestCase;

/**
 * registry-kernel ticket 74 — a registry that COMPOSES a {@see BasicRegistry} forwards the pushed
 * authorizer into it, or argues in its own docblock why it does not.
 *
 * ## Why this is asserted behaviourally and never by reflection
 *
 * Both wrong numbers ticket 74 corrected came from reflection probes, in opposite directions: phase B
 * read `MISSING` without asking why, and 74's own first probe read through
 * {@see RegistryIndex::unfiltered()}, which nulls the authorizer all the way
 * down by design, and reported 55 missing of 64. An instrument that strips the field it is measuring
 * returns a confident wrong number. So every assertion here installs a deny-all authorizer against an
 * ability-carrying entry and reads the registry's own public surface — the observable consequence, not
 * the private field.
 *
 * The probe entry is seeded through the composed store rather than the outer `register()` because the
 * outer signatures narrow their key and entry types per registry; the ASSERTION is entirely through the
 * public read, which is the half that has to be honest.
 */
class ComposedRegistryForwardsAuthorizerTest extends TestCase
{
    private const PROBE_KEY = 'ticket-74-probe';

    private const PROBE_ABILITY = 'ticket-74.probe';

    /** @return array<string, array{0: callable(): Registry, 1: string}> registry factory, composed-store property */
    public static function forwardingRegistries(): array
    {
        return [
            'beam.capabilities' => [fn () => new CapabilityRegistry, 'store'],
            'beam.particle.resource-contributions' => [fn () => new ResourceContributionRegistry, 'store'],
            'beam.realm.overlays' => [fn () => new RealmOverlayRegistry, 'store'],
            'beam.realm.resource-overrides' => [fn () => new RealmResourceRegistry(new RealmRegistry), 'store'],
            'schemas.sources' => [fn () => new SchemaSources, 'store'],
            'beam.surface.groups' => [fn () => new GroupRegistry, 'store'],
        ];
    }

    /**
     * The trusted-shell population. Their non-`Gated` status is a ruling with an argument in each class
     * docblock, so this asserts the ruling holds rather than that nobody got to them — if one of these
     * ever gains `Gated`, the docblock refusal beside it has gone stale and must be removed in the same
     * change.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function refusingRegistries(): array
    {
        return [
            'beam.doctor.audits' => [BeamDoctorManifest::class],
            'beam.install.steps' => [BeamInstallManifest::class],
            'beam.seed.steps' => [BeamSeedManifest::class],
            'beam.surgeon.scan-paths' => [AuditScanPaths::class],
            'beam.doctor.facade-scope' => [FacadeConformanceScope::class],
        ];
    }

    #[DataProvider('forwardingRegistries')]
    public function test_a_deny_all_authorizer_reaches_the_composed_store(callable $make, string $property): void
    {
        /** @var Registry&Gated $registry */
        $registry = $make();

        $this->assertInstanceOf(Gated::class, $registry);

        $store = new ReflectionProperty($registry, $property);
        $store->setAccessible(true);

        /** @var BasicRegistry $inner */
        $inner = $store->getValue($registry);
        $inner->register(self::PROBE_KEY, 'probe-entry', by: self::class, ability: self::PROBE_ABILITY);

        $this->assertTrue(
            $this->probeVisible($registry),
            'the probe entry must be visible before an authorizer is installed — otherwise the deny below proves nothing',
        );

        $registry->authorizeWith(new DenyAllAuthorizer);

        $this->assertFalse(
            $this->probeVisible($registry),
            'a deny-all authorizer installed on the outer registry did not reach the composed store',
        );

        $registry->authorizeWith(null);

        $this->assertTrue(
            $this->probeVisible($registry),
            'removing the authorizer must reopen the surface — last-wins, per Gated',
        );
    }

    #[DataProvider('refusingRegistries')]
    public function test_the_trusted_shell_population_refuses_gated_and_argues_it(string $class): void
    {
        $this->assertFalse(
            is_subclass_of($class, Gated::class),
            $class.' gained Gated — remove the docblock refusal in the same change',
        );

        $source = file_get_contents((new \ReflectionClass($class))->getFileName());

        $this->assertStringContainsString(
            '`Gated` is deliberately absent',
            $source,
            $class.' does not implement Gated and does not say why — ticket 20 requires the refusal be argued',
        );
    }

    /**
     * Whether the probe key is visible on the registry's own public read.
     *
     * Matched by substring because {@see BasicRegistry::door()} stamps the
     * declared root onto a relative key, so the stored key is `<root>.ticket-74-probe` and the exact
     * spelling differs per registry — the key TYPE is each registry's business, not this test's.
     */
    private function probeVisible(Registry $registry): bool
    {
        foreach ($registry->keys() as $key) {
            if (str_contains((string) $key, self::PROBE_KEY)) {
                return true;
            }
        }

        return false;
    }
}

/** Denies everything it is asked about — the only authorizer shape that can prove a push arrived. */
class DenyAllAuthorizer implements Authorizer
{
    public function allows(string $ability, RegistryKey $key): bool
    {
        return false;
    }
}
