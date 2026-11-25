<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Authorization\SeatGate;
use Splicewire\Beam\Discovery\RouteReachability;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;

class SeatRealmProjectionTest extends TestCase
{
    public function test_seat_lookups_share_the_realm_projection_and_keep_actor_checks_live(): void
    {
        $definition = new ResourceDefinition(
            key: 'feeds', model: null, data: WidgetGateData::class, creatable: false,
            query: null, editData: null, policy: 'feed.read', nav: new NavMetadata(label: 'Feeds'),
        );
        $resources = new class($definition) extends ParticleResourceRegistry
        {
            public int $projections = 0;

            public function __construct(private ResourceDefinition $fixture) {}

            public function definitions(?string $realm = null): array
            {
                $this->projections++;

                return $realm === 'tenant' ? [$this->fixture] : [];
            }
        };
        $allowed = $this->createStub(Authenticatable::class);
        $denied = $this->createStub(Authenticatable::class);
        $visibility = $this->createStub(ResourceVisibility::class);
        $visibility->method('readable')->willReturnCallback(
            fn (ResourceDefinition $resource, ?Authenticatable $actor): bool => $actor === $allowed,
        );
        $gate = new SeatGate(
            new Router(new Dispatcher),
            $resources,
            $this->createStub(ParticleOperationRegistry::class),
            $visibility,
            $this->createStub(AbilityResolver::class),
            $this->createStub(RouteReachability::class),
        );

        $resources->withRealmProjection('tenant', function () use ($gate, $resources, $allowed, $denied): void {
            self::assertTrue($gate->for('feeds.index', $allowed, 'tenant'));
            self::assertFalse($gate->for('feeds.index', $denied, 'tenant'));
            self::assertNull($gate->resolve('feeds.index', 'operator'));
            self::assertSame(2, $resources->projections);
        });

        self::assertNotNull($gate->resolve('feeds.index', 'tenant'));
        self::assertSame(3, $resources->projections);
    }

    public function test_registration_invalidates_an_outer_realm_projection(): void
    {
        $resources = new class extends ParticleResourceRegistry
        {
            public function definitions(?string $realm = null): array
            {
                return [];
            }
        };
        $resource = new ParticleResource(key: 'feeds', backing: $this->createStub(StreamsRecords::class), readOnly: true);

        $resources->withRealmProjection('tenant', function () use ($resources, $resource): void {
            $first = $resources->currentRealmProjection('tenant');
            $resources->withRealmProjection('operator', function () use ($resources, $resource): void {
                $resources->currentRealmProjection('operator');
                $resources->register($resource);
            });

            self::assertNotSame($first, $resources->currentRealmProjection('tenant'));
        });
    }

    public function test_the_projection_is_discarded_when_the_build_throws(): void
    {
        $resources = new ParticleResourceRegistry;

        try {
            $resources->withRealmProjection('tenant', function () use ($resources): never {
                self::assertNotNull($resources->currentRealmProjection('tenant'));

                throw new RuntimeException('build interrupted');
            });
            self::fail('The build exception should escape.');
        } catch (RuntimeException $error) {
            self::assertSame('build interrupted', $error->getMessage());
        }

        self::assertNull($resources->currentRealmProjection('tenant'));
    }
}
