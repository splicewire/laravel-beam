<?php

namespace Splicewire\Beam\Tests\Discovery;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Discovery\ResourceDiscoveryAutoMounter;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Rendering\DeclaresDelivery;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Tests\TestCase;

/**
 * The rendering catalog's inheritance (particle-operation-surface 13).
 *
 * `GET {resource}/renderings` published four facts per rendering — the format enumeration, the media
 * types, the added headers and the applied default — out of a `RenderingDescriptorData` and a
 * controller of its own. 13 dissolved the registry behind it, and the three endpoints it described
 * became OPERATIONS. `GET {mount}/discovery` already enumerated a resource's operations, per mount,
 * mounting even at zero entries, so the facts moved onto the entry that was already there rather than
 * a second listing of the same shape being invented; the catalog route went.
 *
 * The load-bearing property is that they are read LIVE off the operation's declared `delivery:`,
 * through the one resolver the controller and the Scribe strategy also read. The composition export's
 * format set is enumerated off a profile registry, so a set frozen at mount time would have gone stale
 * the moment a profile registered — which is the discipline the catalog kept and the reason it re-read
 * per request.
 *
 * The one thing a reader loses is the `writable`/`fidelity` pair. It described a write verb that has
 * never been mounted for any rendering in the estate (`RenderingCertifier` floors all four implementors
 * at `Lossy`), so an absent route is now the only statement of it.
 */
class OperationDiscoveryDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'papers',
            name: 'export',
            kind: OperationKind::Read,
            model: 'App\\Models\\Paper',
            method: HttpMethod::Get,
            delivery: new PaperExportDelivery,
            handle: fn () => null,
        ));

        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'papers',
            name: 'reindex',
            kind: OperationKind::Task,
            model: 'App\\Models\\Paper',
            handle: fn () => null,
        ));
    }

    /** The discovery entry for one operation of the `papers` mount. */
    protected function entry(string $operation): array
    {
        Particle::ops('papers', 'papers', ['export', 'reindex'], ['alias' => false]);

        // The auto-mounter runs from the framework's `booted` hook in a real app; a test mounting
        // post-boot has to call it itself.
        app(ResourceDiscoveryAutoMounter::class)->mount();

        Route::getRoutes()->refreshNameLookups();

        $body = $this->getJson('papers/discovery')->assertOk()->json('data.entries');

        foreach ($body as $entry) {
            if (($entry['operation'] ?? null) === $operation) {
                return $entry;
            }
        }

        $this->fail("No discovery entry for operation [{$operation}].");
    }

    public function test_a_declaring_operation_publishes_its_wire_contract(): void
    {
        $entry = $this->entry('export');

        $this->assertTrue($entry['declaresDelivery']);
        $this->assertSame(['html', 'pdf'], $entry['formats']);
        $this->assertSame(['text/html', 'application/pdf'], $entry['mediaTypes']);
        $this->assertSame(['X-Cost-Usd' => 'What it cost.'], $entry['deliveryHeaders']);
        $this->assertSame('html', $entry['defaultFormat']);
    }

    public function test_an_operation_declaring_no_delivery_says_so_rather_than_publishing_an_empty_contract(): void
    {
        $entry = $this->entry('reindex');

        $this->assertFalse(
            $entry['declaresDelivery'],
            'An undeclared delivery is an absence worth closing; collapsing it into "no media types" '
                .'would make it read as a decision — the distinction RenderingDescriptorData kept.',
        );

        $this->assertSame([], $entry['formats']);
        $this->assertSame([], $entry['mediaTypes']);
        $this->assertNull($entry['defaultFormat']);
    }

    /**
     * The mount root of an operation-only resource.
     *
     * `ResourceMountMap::rootOf()` sliced an operation's URI before the literal `op` segment. ticket 12
     * dropped that segment, and `sliceBeforeLast()` on a word that is no longer there returns the
     * segments UNCHANGED — so the trailing-parameter strip never reached `{id}` and the root came back
     * as `papers/{id}/export`. It stayed invisible for as long as every operation-bearing resource also
     * mounted CRUD or filters, which computed the same root correctly and absorbed the wrong one. 13
     * made `disclosures` the first resource whose ONLY mounted route is an operation, and its discovery
     * listing moved to `api/v1/disclosures/{id}/export/discovery`.
     */
    public function test_an_operation_only_resource_mounts_its_discovery_at_the_resource_root(): void
    {
        Particle::ops('papers', 'papers', 'export', ['alias' => false]);

        app(ResourceDiscoveryAutoMounter::class)->mount();

        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('papers.discovery');

        $this->assertNotNull($route, 'An operation-only mount still auto-mounts its discovery listing.');
        $this->assertSame('papers/discovery', $route->uri());
    }
}

/** A two-format delivery — the shape `DisclosureDocumentRendering` has, minus the host renderer. */
class PaperExportDelivery implements DeclaresDelivery
{
    public function mediaTypes(): array
    {
        return ['text/html', 'application/pdf'];
    }

    public function deliveryHeaders(): array
    {
        return ['X-Cost-Usd' => 'What it cost.'];
    }

    public function defaultFormat(): ?string
    {
        return 'html';
    }

    public function formats(): array
    {
        return ['html', 'pdf'];
    }
}
