<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Foundation\Auth\User;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Rushing\Popcorn\Laravel\Facades\Popcorn;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Exceptions\MissReason;
use Rushing\Popcorn\Registries\Exceptions\RegistryMiss;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\EntitlementRegistryAuthorizer;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Entitlements\EntitlementGate;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * registry-kernel ticket 27 — beam's {@see EntitlementRegistryAuthorizer} against the real entitlement
 * stack, end to end.
 *
 * Nothing here is stubbed below the adapter: the ability strings are defined as real `entitlement:{key}`
 * Gate abilities by {@see BeamServiceProvider::registerEntitlementAbilities()}, they
 * delegate to the real {@see EntitlementGate}, which asks a bound
 * {@see EntitlementResolver} — the one seam a host supplies. The registry is a real `BasicRegistry`
 * described into the container's real singleton {@see RegistryIndex}, and the authorizer is pushed down
 * by the index exactly as a host's `Popcorn::authorizeWith()` call does it.
 *
 * The particle registry composition test also exercises ticket 27's acceptance against the real
 * {@see ParticleResourceRegistry}. It declares ability and realm membership through the public
 * registration seam, then reads the manifest as actors gain and lose entitlement. The shared
 * {@see RegistryIndex} pushes the authorizer into that registry without per-registry wiring.
 */
class RegistryAuthorizerTest extends TestCase
{
    /** The registry under test — a real `BasicRegistry`, described into the real index. */
    private BasicRegistry $registry;

    private MutableActorPort $port;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The host's half of the entitlement seam. Without a bound resolver, `registerEntitlementAbilities()`
        // returns EARLY and defines no abilities at all — which is the bare-install case this adapter must
        // not be installed by default into. See the class docblock on EntitlementRegistryAuthorizer.
        $app['config']->set('permission-cascade.entitlement_resolver', fn () => new PlanEntitlementResolver);

        // The key universe the Gate abilities are defined over. An ability outside this list is UNDEFINED,
        // and `Gate::allows()` answers false for an undefined ability — see the last test.
        $app['config']->set('beam.core.entitlements.keys', ['workbench.enter']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->port = new MutableActorPort(new UnentitledUser);
        $this->app->instance(ActorPort::class, $this->port);

        $this->registry = new BasicRegistry(new IsRegistry(
            root: 'beam.test.gated',
            entryType: 'string',
            onKeyDuplicate: OnKeyDuplicate::Reject,
            description: 'registry-kernel ticket 27 fixtures',
        ));

        $this->registry
            ->register('open', 'open-entry', by: self::class)
            ->register('workbench', 'gated-entry', by: self::class, ability: 'workbench.enter');

        $this->app->make(RegistryIndex::class)->describe($this->registry, by: self::class);
    }

    /** Install the estate's one authorizer, the way a host app opts in. */
    private function installAuthorizer(?Authorizer $authorizer = null): void
    {
        Popcorn::authorizeWith($authorizer ?? $this->app->make(EntitlementRegistryAuthorizer::class));
    }

    // ── Shipped, not installed ───────────────────────────────────────────────────────────────────────

    public function test_no_package_installs_an_authorizer_so_a_gated_entry_is_open_until_a_host_opts_in(): void
    {
        // Beam's provider has booted, the adapter is resolvable, and NOTHING is filtering. This is the
        // designed state (registry-kernel ticket 20 D9): there is one authorizer for the estate, so a
        // package that installed one would choose policy for a host that never asked.
        $this->assertInstanceOf(
            EntitlementRegistryAuthorizer::class,
            $this->app->make(EntitlementRegistryAuthorizer::class),
        );

        $this->assertTrue($this->registry->has('beam.test.gated.workbench'));
        $this->assertSame('gated-entry', $this->registry->resolve('beam.test.gated.workbench'));
    }

    // ── A denied entry is absent from EVERY read, not just from resolution ───────────────────────────

    public function test_a_denied_entry_is_missing_from_enumeration_and_from_a_direct_hit_alike(): void
    {
        $this->installAuthorizer();

        // UnentitledUser holds nothing.
        $this->assertFalse($this->registry->has('beam.test.gated.workbench'));
        $this->assertNull($this->registry->tryResolve('beam.test.gated.workbench'));

        $this->assertSame(
            ['beam.test.gated.open'],
            array_map('strval', $this->registry->keys()),
            'A filtered entry must not appear in keys() — an enumeration that disagrees with a direct hit '
                .'is the existence oracle the filter exists to prevent.',
        );

        // `matches()` returns ENTRIES, so the filter has to bite before the value is handed over — which
        // is the point of the seam taking the declared ability rather than the resolved entry.
        $this->assertSame(['open-entry'], $this->registry->matches('beam.test.gated'));
    }

    public function test_the_miss_carries_filtered_while_rendering_as_absent(): void
    {
        $this->installAuthorizer();

        try {
            $this->registry->resolve('beam.test.gated.workbench');
            $this->fail('A filtered key must miss.');
        } catch (RegistryMiss $miss) {
            $this->assertSame(MissReason::Filtered, $miss->reason, 'The doctor and the log need the truth.');
            $this->assertSame(
                RegistryMiss::absent('beam.test.gated.workbench')->getMessage(),
                $miss->getMessage(),
                'The CALLER must not be able to tell a hidden key from an absent one.',
            );
        }
    }

    public function test_an_entitled_actor_sees_the_same_entry(): void
    {
        $this->installAuthorizer();
        $this->port->becomes(new SubscriberUser);

        $this->assertTrue($this->registry->has('beam.test.gated.workbench'));
        $this->assertSame('gated-entry', $this->registry->resolve('beam.test.gated.workbench'));
        $this->assertSame(
            ['beam.test.gated.open', 'beam.test.gated.workbench'],
            array_map('strval', $this->registry->keys()),
        );
    }

    // ── The no-narrowing guarantee ───────────────────────────────────────────────────────────────────

    public function test_an_entry_declaring_no_ability_is_never_put_to_the_authorizer(): void
    {
        $spy = new CountingAuthorizer($this->app->make(EntitlementRegistryAuthorizer::class));
        $this->installAuthorizer($spy);

        $this->assertSame('open-entry', $this->registry->resolve('beam.test.gated.open'));
        $this->assertSame(0, $spy->calls, 'An ungated entry short-circuits INSIDE the registry.');

        // …and the gated sibling proves the spy was wired, so the zero above is a short-circuit and not a
        // dead authorizer.
        $this->registry->has('beam.test.gated.workbench');
        $this->assertSame(1, $spy->calls);
        $this->assertSame(['workbench.enter'], $spy->abilities);
    }

    // ── The result is per-actor and is NOT cached across them ────────────────────────────────────────

    public function test_two_actors_read_two_different_key_sets_from_one_index(): void
    {
        $this->installAuthorizer();

        $this->port->becomes(new SubscriberUser);
        $entitled = array_map('strval', $this->app->make(RegistryIndex::class)->resolve('beam.test.gated')->keys());

        $this->port->becomes(new UnentitledUser);
        $plain = array_map('strval', $this->app->make(RegistryIndex::class)->resolve('beam.test.gated')->keys());

        $this->assertSame(['beam.test.gated.open', 'beam.test.gated.workbench'], $entitled);
        $this->assertSame(['beam.test.gated.open'], $plain);
    }

    // ── The actor arrives through the port, not through ambient auth ─────────────────────────────────

    public function test_the_decision_follows_the_actor_port_rather_than_the_ambient_guard_user(): void
    {
        $this->installAuthorizer();

        // Ambient auth says PLAIN (would be filtered); the port says SUBSCRIBER. The port wins, which is
        // the whole reason the kernel seam has no actor parameter — a transport with no ambient user
        // (MCP over stdio) substitutes its own source and nothing downstream changes.
        $this->actingAs(new UnentitledUser);
        $this->port->becomes(new SubscriberUser);

        $this->assertTrue($this->registry->has('beam.test.gated.workbench'));
    }

    // ── The prefix belongs to AbilityResolver and cannot be applied twice ────────────────────────────

    public function test_an_already_prefixed_ability_passes_through_without_a_double_prefix(): void
    {
        $this->registry->register('prefixed', 'entry', by: self::class, ability: 'entitlement:workbench.enter');
        $this->installAuthorizer();
        $this->port->becomes(new SubscriberUser);

        $this->assertTrue(
            $this->registry->has('beam.test.gated.prefixed'),
            '`entitlementAbility()` makes a double prefix impossible, so the adapter must not re-prefix.',
        );
    }

    // ── The failure mode that keeps this out of the default wiring ───────────────────────────────────

    public function test_an_ability_no_host_has_defined_hides_the_entry_which_is_why_this_is_opt_in(): void
    {
        // `unowned.feature` is not in `beam.core.entitlements.keys`, so no Gate ability was defined for it
        // and `Gate::allows()` answers FALSE. Installed by default, that turns "nobody has written the
        // policy yet" into fleet-wide silent invisibility — the `LensRegistry` failure class. Pinned here
        // so the opt-in argument on EntitlementRegistryAuthorizer has a test under it rather than prose.
        $this->registry->register('orphan', 'entry', by: self::class, ability: 'unowned.feature');
        $this->installAuthorizer();
        $this->port->becomes(new SubscriberUser);

        $this->assertFalse($this->registry->has('beam.test.gated.orphan'));
    }

    // ── Ability and realm composition through the migrated particle registry ────────────────────────

    public function test_ability_and_realm_are_orthogonal_axes_that_compose(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);
        $index = $this->app->make(RegistryIndex::class);

        $this->assertSame($index, $this->app->make(RegistryIndex::class));
        $this->assertSame($registry, $index->resolve('beam.particle.resources'));

        foreach ([
            ['authorizer-operator-gated', 'operator', 'workbench.enter'],
            ['authorizer-tenant-gated', 'tenant', 'workbench.enter'],
            ['authorizer-operator-open', 'operator', null],
        ] as [$key, $realm, $ability]) {
            $registry->register(new ParticleResource(
                key: $key,
                backing: UnentitledUser::class,
                data: RegistryAuthorizerResourceData::class,
                label: $key,
            ), [$realm], ability: $ability);
        }

        $this->installAuthorizer();
        $keys = fn (string $realm) => array_values(array_filter(
            array_column($registry->definitions($realm), 'key'),
            fn (string $key) => str_starts_with($key, 'authorizer-'),
        ));

        $this->assertSame(['authorizer-operator-open'], $keys('operator'));
        $this->assertSame([], $keys('tenant'));
        $this->assertFalse($registry->has('authorizer-operator-gated'));

        $this->port->becomes(new SubscriberUser);

        $this->assertSame(['authorizer-operator-gated', 'authorizer-operator-open'], $keys('operator'));
        $this->assertSame(['authorizer-tenant-gated'], $keys('tenant'));
        $this->assertTrue($registry->has('authorizer-operator-gated'));

        $this->port->becomes(new UnentitledUser);

        $this->assertSame(['authorizer-operator-open'], $keys('operator'));
        $this->assertFalse($registry->has('authorizer-operator-gated'));
    }
}

class RegistryAuthorizerResourceData extends Data
{
    public function __construct(public string $id) {}
}

/** A host resolver: a SubscriberUser holds the workbench entitlement, everyone else holds nothing. */
class PlanEntitlementResolver implements EntitlementResolver
{
    public function entitlementsFor(mixed $principal): array
    {
        return $principal instanceof SubscriberUser ? ['workbench.enter'] : [];
    }
}

class UnentitledUser extends User
{
    protected $table = 'users';
}

class SubscriberUser extends User
{
    protected $table = 'users';
}

/** A port whose actor can be swapped mid-test — one authorizer instance, two actors. */
class MutableActorPort implements ActorPort
{
    public function __construct(private mixed $actor) {}

    public function becomes(mixed $actor): void
    {
        $this->actor = $actor;
    }

    public function actor(): mixed
    {
        return $this->actor;
    }
}

/** A pass-through decorator that counts what it was asked — the no-narrowing assertion needs a witness. */
class CountingAuthorizer implements Authorizer
{
    public int $calls = 0;

    /** @var list<string> */
    public array $abilities = [];

    public function __construct(private Authorizer $inner) {}

    public function allows(string $ability, RegistryKey $key): bool
    {
        $this->calls++;
        $this->abilities[] = $ability;

        return $this->inner->allows($ability, $key);
    }
}
