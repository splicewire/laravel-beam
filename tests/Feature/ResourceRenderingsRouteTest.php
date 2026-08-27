<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Schemastud\DataSchemas\Overlay\Lens\Fidelity;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Rendering\Http\RenderingsController;
use Splicewire\Beam\Rendering\RenderingCertifier;
use Splicewire\Beam\Rendering\ResourceRenderingRegistry;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Tests\Fixtures\Rendering\LossyTitleLens;
use Splicewire\Beam\Tests\Fixtures\Rendering\MirrorRendering;
use Splicewire\Beam\Tests\Fixtures\Rendering\RenderingSubject;
use Splicewire\Beam\Tests\Fixtures\Rendering\TranscriptRendering;
use Splicewire\Beam\Tests\TestCase;

/**
 * `Route::resourceRenderings()` end to end: one call mounts a resource's renderings off the registry, and
 * CERTIFIED fidelity — never a declared claim — decides which verbs exist.
 *
 * Moved from laravel-composition-engine into beam core (the macro's new home). Converted from the
 * origin repo's Pest functional style to beam's PHPUnit class-based convention — beam carries no Pest
 * dependency, so the suite could not simply be copied byte-for-byte.
 */
class ResourceRenderingsRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RenderingSubject::reset();
        RenderingSubject::seed('doc-1', 'Hello');
        TranscriptRendering::$formats = ['text', 'html'];
    }

    /** Register renderings, then mount them. Returns the registry so a test can keep adding. */
    private function renderings(array $renderings, string $resource = 'papers'): ResourceRenderingRegistry
    {
        $registry = app(ResourceRenderingRegistry::class);

        foreach ($renderings as $rendering) {
            $registry->register($resource, $rendering);
        }

        return $registry;
    }

    /**
     * Name lookups are built when a route is ADDED, and the macro names each route after adding it —
     * exactly as `recordVersions()` does — so a test that mounts routes post-boot must refresh the
     * lookups itself (a real app gets this free from the framework's `booted` hook).
     */
    private function routeNamed(string $name): ?\Illuminate\Routing\Route
    {
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->getByName($name);
    }

    public function test_mounts_every_registered_rendering_for_a_resource_from_one_macro_call(): void
    {
        $this->renderings([new TranscriptRendering, new MirrorRendering]);

        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertNotNull($this->routeNamed('papers.transcript'));
        $this->assertSame('papers/{id}/transcript', $this->routeNamed('papers.transcript')->uri());
        $this->assertNotNull($this->routeNamed('papers.mirror'));
        $this->assertSame('papers/{id}/mirror', $this->routeNamed('papers.mirror')->uri());

        // The route file named a RESOURCE; it never named a rendering.
        $this->get('papers/doc-1/transcript')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('X-Rendering', 'transcript')
            ->assertSee('text:Hello');
    }

    public function test_adding_a_rendering_to_the_registry_mounts_its_route_with_no_route_file_edit(): void
    {
        $this->renderings([new TranscriptRendering]);

        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertNull($this->routeNamed('papers.mirror'));

        // The only change: a new registry entry. The macro call is untouched — same line, same arguments.
        $this->renderings([new MirrorRendering]);

        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertNotNull($this->routeNamed('papers.mirror'));
    }

    /**
     * The config has to be in place BEFORE beam boots, because the seeding is a `ConfigRegistrar`
     * attached in beam's own `boot()` rather than a constructor read at first resolve (registry-kernel
     * ticket 53). Setting `config()` mid-test and forgetting the singleton — what this test used to do —
     * would now prove nothing about a host, since a host's config is always in place before boot.
     */
    protected function seedRenderingFromConfig(Application $app): void
    {
        $app['config']->set('beam.core.renderings', ['papers' => [TranscriptRendering::class]]);
    }

    #[DefineEnvironment('seedRenderingFromConfig')]
    public function test_seeds_the_registry_from_config_so_a_host_adds_a_rendering_without_touching_code(): void
    {
        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertNotNull($this->routeNamed('papers.transcript'));
        $this->assertSame(
            ['config beam.core.renderings'],
            array_map(
                fn ($registrar) => $registrar->source(),
                app(ResourceRenderingRegistry::class)->registrars(),
            ),
        );
    }

    public function test_mounts_a_read_and_a_write_verb_for_a_rendering_whose_reversibility_is_certified(): void
    {
        $this->renderings([new MirrorRendering]);

        $this->assertSame(Fidelity::LosslessEligible, app(RenderingCertifier::class)->certify(new MirrorRendering));

        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertContains('GET', $this->routeNamed('papers.mirror')->methods());
        $this->assertNotNull($this->routeNamed('papers.mirror.ingest'));
        $this->assertContains('POST', $this->routeNamed('papers.mirror.ingest')->methods());

        $this->get('papers/doc-1/mirror')->assertOk()->assertJson(['read' => 'Hello']);
        $this->post('papers/doc-1/mirror', ['title' => 'Edited'])->assertOk()->assertJson(['wrote' => 'Edited']);
    }

    public function test_mounts_a_read_verb_only_for_a_lossy_rendering_and_no_write_verb_exists_at_all(): void
    {
        $this->renderings([new TranscriptRendering]);

        $this->assertSame(Fidelity::Lossy, app(RenderingCertifier::class)->certify(new TranscriptRendering));

        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertNotContains('POST', $this->routeNamed('papers.transcript')->methods());
        $this->assertNull($this->routeNamed('papers.transcript.ingest'));

        // Not a warning, not a silent discard — the verb is absent from the table.
        $writeRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->uri() === 'papers/{id}/transcript' && in_array('POST', $route->methods(), true));

        $this->assertTrue($writeRoutes->isEmpty());

        $this->post('papers/doc-1/transcript')->assertStatus(405);
    }

    public function test_reads_fidelity_from_certification_and_refuses_a_lossless_claim_its_lens_cannot_back(): void
    {
        // Claims LosslessEligible (LensAssociation::bijective) over a lens that fails GetPut.
        $liar = new MirrorRendering('boastful', new LossyTitleLens);

        $this->assertSame(Fidelity::LosslessEligible, $liar->association()->fidelity);
        $this->assertTrue($liar->association()->isLosslessEligible());

        // The claim is not the verdict: exercising the laws downgrades it.
        $this->assertSame(Fidelity::Lossy, app(RenderingCertifier::class)->certify($liar));

        $this->renderings([$liar]);

        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertNotNull($this->routeNamed('papers.boastful'));
        $this->assertNull($this->routeNamed('papers.boastful.ingest'));
        $this->assertSame('lossy', $this->routeNamed('papers.boastful')->defaults['_renderings']['fidelity']);
        $this->assertFalse($this->routeNamed('papers.boastful')->defaults['_renderings']['writable']);
    }

    public function test_treats_an_unexercised_lossless_claim_as_uncertified_rather_than_as_proven(): void
    {
        // A lawful lens and an honest-looking claim — but zero samples. The laws over an empty sample set
        // are vacuously true, so "submitted nothing" must not read as "survived everything".
        $unproven = new MirrorRendering('unexercised', proven: false);

        $this->assertSame(Fidelity::LosslessEligible, $unproven->association()->fidelity);
        $this->assertTrue($unproven->reversibilityProof()->empty());
        $this->assertSame(Fidelity::Lossy, app(RenderingCertifier::class)->certify($unproven));

        $this->renderings([$unproven]);

        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertNull($this->routeNamed('papers.unexercised.ingest'));
    }

    public function test_carries_its_config_on_route_defaults_with_no_closures_so_the_table_stays_route_cache_safe(): void
    {
        $this->renderings([new TranscriptRendering, new MirrorRendering]);

        Particle::renderings('papers', RenderingSubject::class, with: ['cells'], idConstraint: 'none');

        $route = $this->routeNamed('papers.transcript');

        $this->assertSame([
            'resource' => 'papers',
            'rendering' => 'transcript',
            'subject' => RenderingSubject::class,
            'with' => ['cells'],
            'abilities' => ['view' => 'view', 'mutate' => 'update'],
            'fidelity' => 'lossy',
            'writable' => false,
        ], $route->defaults['_renderings']);

        // Controller actions, never closures — the two things route:cache cannot swallow are a Closure
        // action and a Closure in defaults.
        foreach (['papers.transcript', 'papers.mirror', 'papers.mirror.ingest'] as $name) {
            $this->assertStringStartsWith(RenderingsController::class.'@', $this->routeNamed($name)->getActionName());
            $this->assertIsString(serialize($this->routeNamed($name)->defaults));
        }

        // And the real proof: exactly what `route:cache` does to the table — prepare every route for
        // serialization (which THROWS on a Closure action) then var_export the compiled collection.
        foreach (Route::getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $this->assertIsString(var_export(Route::getRoutes()->compile(), true));
        $this->assertStringContainsString('_renderings', var_export(Route::getRoutes()->compile(), true));
    }

    public function test_mounts_at_the_current_group_root_when_at_is_empty_preserving_an_existing_uri_and_name(): void
    {
        $this->renderings([new TranscriptRendering], 'compositions');

        // How an already-grouped endpoint migrates: the group owns the prefix and name, the macro adds
        // only the `{id}/{rendering}` tail — byte-identical to the hand-mounted route it replaces.
        Route::prefix('splice/compositions')->name('splice.compositions.')->group(function () {
            Particle::renderings('compositions', RenderingSubject::class, at: '', abilities: [], idConstraint: 'none');
        });

        $route = $this->routeNamed('splice.compositions.transcript');

        $this->assertNotNull($route);
        $this->assertSame('splice/compositions/{id}/transcript', $route->uri());

        $this->get('splice/compositions/doc-1/transcript')->assertOk()->assertSee('text:Hello');
    }

    public function test_forwards_format_untouched_and_substitutes_no_default_of_its_own(): void
    {
        $this->renderings([new TranscriptRendering]);

        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->get('papers/doc-1/transcript?format=html')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('html:Hello');

        // No format at all: the RENDERING's default applies, not the controller's.
        $this->get('papers/doc-1/transcript')->assertSee('text:Hello');
    }

    public function test_reads_the_format_enumeration_live_rather_than_freezing_it_into_the_route_table(): void
    {
        $this->renderings([new TranscriptRendering]);

        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        // Nothing about the enumeration rides the defaults, so widening it needs no remount.
        $this->assertArrayNotHasKey('formats', $this->routeNamed('papers.transcript')->defaults['_renderings']);

        TranscriptRendering::$formats = ['text', 'html', 'pdf'];

        $this->assertSame(
            ['text', 'html', 'pdf'],
            app(ResourceRenderingRegistry::class)->find('papers', 'transcript')->formats(),
        );
    }

    public function test_applies_the_declared_eager_loads_so_a_migrated_endpoint_keeps_its_query_shape(): void
    {
        $this->renderings([new TranscriptRendering]);

        Particle::renderings('papers', RenderingSubject::class, abilities: [], with: ['cells'], idConstraint: 'none');

        $this->get('papers/doc-1/transcript')->assertOk();

        $this->assertSame(['cells'], RenderingSubject::$eagerLoaded);
    }

    public function test_authorizes_through_the_ability_map_and_treats_an_empty_map_as_explicitly_ungated(): void
    {
        Gate::define('view', fn (?object $user, object $subject) => false);

        $this->renderings([new TranscriptRendering]);
        Particle::renderings('papers', RenderingSubject::class, idConstraint: 'none');

        $this->get('papers/doc-1/transcript')->assertForbidden();

        $this->renderings([new TranscriptRendering], 'open-papers');
        Particle::renderings('open-papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->get('open-papers/doc-1/transcript')->assertOk();
    }

    public function test_constrains_id_to_a_uuid_by_default_and_leaves_it_open_on_request(): void
    {
        $this->renderings([new TranscriptRendering]);

        Particle::renderings('papers', RenderingSubject::class, abilities: []);

        $this->assertArrayHasKey('id', $this->routeNamed('papers.transcript')->wheres);

        $this->renderings([new TranscriptRendering], 'loose');
        Particle::renderings('loose', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->assertArrayNotHasKey('id', $this->routeNamed('loose.transcript')->wheres);
    }

    // ── format validation (api-surface-coherence ticket 32 §D) ────────────────────────────────────────

    public function test_rejects_a_format_outside_the_renderings_enumeration_with_a_422_on_format(): void
    {
        $this->renderings([new TranscriptRendering]);
        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        // Before this ticket the controller forwarded anything and each rendering rejected in its own
        // shape — which for the disclosure surface meant a bare InvalidArgumentException and a 500.
        $this->getJson('papers/doc-1/transcript?format=xml')
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    public function test_does_not_echo_the_rejected_format_back_but_does_name_the_accepted_set(): void
    {
        $this->renderings([new TranscriptRendering]);
        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $message = $this->getJson('papers/doc-1/transcript?format=<script>')
            ->assertStatus(422)
            ->json('errors.format.0');

        $this->assertStringNotContainsString('<script>', $message);
        $this->assertStringContainsString('text', $message);
        $this->assertStringContainsString('html', $message);
    }

    public function test_validates_against_the_live_enumeration_rather_than_the_set_frozen_at_mount(): void
    {
        TranscriptRendering::$formats = ['text'];

        $this->renderings([new TranscriptRendering]);
        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        $this->getJson('papers/doc-1/transcript?format=html')->assertStatus(422);

        // The route table is untouched; only the registry widened. The rendering is re-read per request,
        // so the enforced set widens with it — the same property the mount already had for the route set.
        TranscriptRendering::$formats = ['text', 'html'];

        $this->getJson('papers/doc-1/transcript?format=html')->assertOk();
    }

    public function test_a_rendering_enumerating_no_formats_accepts_anything_because_it_reads_nothing(): void
    {
        TranscriptRendering::$formats = [];

        $this->renderings([new TranscriptRendering]);
        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        // One representation, no format axis. Rejecting a parameter it has never read would be a new
        // behaviour dressed as a fix — this is the circuits case, decided in ticket 09 §5.
        $this->get('papers/doc-1/transcript?format=anything')
            ->assertOk()
            ->assertSee('anything:Hello');
    }

    public function test_still_refuses_to_substitute_a_default_so_the_renderings_own_default_survives(): void
    {
        $this->renderings([new TranscriptRendering]);
        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        // Validation lifted; defaulting did not. `null` still means "the rendering's own default".
        $this->get('papers/doc-1/transcript')->assertOk()->assertSee('text:Hello');
    }

    public function test_a_rendering_route_reports_the_resource_it_belongs_to(): void
    {
        $this->renderings([new TranscriptRendering]);
        Particle::renderings('papers', RenderingSubject::class, abilities: [], idConstraint: 'none');

        // Ticket 32 §F: the third stamp `BeamRouteAction::resourceKey()` reads. It is what let the
        // hand-placed "Renderings & Export" group be DELETED rather than replaced — the route already
        // knew it belonged to `papers`, and nothing was reading it.
        $this->assertSame('papers', BeamRouteAction::resourceKey($this->routeNamed('papers.transcript')));
    }

    public function test_refuses_a_write_through_a_stale_route_whose_certification_no_longer_grants_it(): void
    {
        $this->renderings([new MirrorRendering]);

        // Hand-mount the write verb with a defaults block that does NOT grant it — the shape a route
        // cache baked under an earlier certification would have. The absent route is the mechanism; this
        // is the belt.
        Route::post('stale/{id}/mirror', [RenderingsController::class, 'store'])->defaults('_renderings', [
            'resource' => 'papers',
            'rendering' => 'mirror',
            'subject' => RenderingSubject::class,
            'with' => [],
            'abilities' => [],
            'fidelity' => 'lossy',
            'writable' => false,
        ]);

        $this->post('stale/doc-1/mirror', ['title' => 'Nope'])->assertStatus(500);

        $this->assertSame('Hello', RenderingSubject::findOrFail('doc-1')->title);
    }
}
