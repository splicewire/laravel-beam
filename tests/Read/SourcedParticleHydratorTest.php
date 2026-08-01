<?php

namespace Splicewire\Beam\Tests\Read;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Read\Contracts\ParticleHydrator;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Read\SourcedParticleHydrator;
use Splicewire\Beam\Source\Contracts\ParticleSourceResolver;
use Splicewire\Beam\Source\ParticleSource;

/**
 * The hydrator router (ADR-0161 ticket 02): proven framework-free that it delegates by RESOLVED source
 * kind — never by prefix-sniffing. A local ref (or a loaded model/payload) hits the local arm; a foreign
 * ref (conduit/federated) hits the federated arm (the socket the degenerate local reader throws on). List
 * queries + projection are local by construction.
 */
class SourcedParticleHydratorTest extends TestCase
{
    private function router(ParticleSource $resolveTo, ProbeArm $local, ProbeArm $federated): SourcedParticleHydrator
    {
        return new SourcedParticleHydrator(new FixedSourceResolver($resolveTo), $local, $federated);
    }

    public function test_a_local_string_ref_routes_to_the_local_arm(): void
    {
        $local = new ProbeArm('local');
        $federated = new ProbeArm('federated');

        $data = $this->router(ParticleSource::local('content/article/7'), $local, $federated)
            ->hydrate('content/article/7', new ReadContext);

        $this->assertSame('local', $data->arm);
        $this->assertTrue($local->hydrateCalled);
        $this->assertFalse($federated->hydrateCalled);
    }

    public function test_a_conduit_ref_routes_to_the_federated_arm(): void
    {
        $local = new ProbeArm('local');
        $federated = new ProbeArm('federated');

        $data = $this->router(ParticleSource::conduit('numero:default.explain', 'numero:default.explain'), $local, $federated)
            ->hydrate('numero:default.explain', new ReadContext);

        $this->assertSame('federated', $data->arm);
        $this->assertTrue($federated->hydrateCalled);
        $this->assertFalse($local->hydrateCalled);
    }

    public function test_a_federated_ref_routes_to_the_federated_arm(): void
    {
        $local = new ProbeArm('local');
        $federated = new ProbeArm('federated');

        $this->router(ParticleSource::federated('federation:g:e', 'g', 'e'), $local, $federated)
            ->hydrate('federation:g:e', new ReadContext);

        $this->assertTrue($federated->hydrateCalled);
        $this->assertFalse($local->hydrateCalled);
    }

    public function test_a_raw_payload_array_is_local_without_resolving(): void
    {
        $local = new ProbeArm('local');
        $federated = new ProbeArm('federated');

        // A resolver that would throw if consulted — a non-string source must never reach it.
        $router = new SourcedParticleHydrator(new ThrowingResolver, $local, $federated);
        $data = $router->hydrate(['title' => 'Hi'], new ReadContext);

        $this->assertSame('local', $data->arm);
        $this->assertFalse($federated->hydrateCalled);
    }

    public function test_list_query_delegates_to_the_local_arm(): void
    {
        $local = new ProbeArm('local');
        $federated = new ProbeArm('federated');

        $this->router(ParticleSource::local('x'), $local, $federated)->query('content/article', new ReadContext);

        $this->assertTrue($local->queryCalled);
        $this->assertFalse($federated->queryCalled);
    }
}

/** A ParticleHydrator arm that records which methods were called and stamps the Data with its label. */
class ProbeArm implements ParticleHydrator
{
    public bool $hydrateCalled = false;

    public bool $queryCalled = false;

    public bool $projectCalled = false;

    public function __construct(private string $label) {}

    public function hydrate(Model|array|string $source, ReadContext $ctx): Data
    {
        $this->hydrateCalled = true;

        return new ProbeData($this->label);
    }

    public function query(string $recordType, ReadContext $ctx): object
    {
        $this->queryCalled = true;

        return (object) ['arm' => $this->label];
    }

    public function project(Model $record, ReadContext $ctx): Data
    {
        $this->projectCalled = true;

        return new ProbeData($this->label);
    }
}

/** A resolver that always returns a fixed source descriptor. */
class FixedSourceResolver implements ParticleSourceResolver
{
    public function __construct(private ParticleSource $source) {}

    public function resolve(string $ref): ParticleSource
    {
        return $this->source;
    }
}

/** A resolver that must never be consulted — asserts the non-string short-circuit. */
class ThrowingResolver implements ParticleSourceResolver
{
    public function resolve(string $ref): ParticleSource
    {
        throw new \LogicException('resolver must not be consulted for a non-string source');
    }
}

class ProbeData extends Data
{
    public function __construct(public string $arm) {}
}
