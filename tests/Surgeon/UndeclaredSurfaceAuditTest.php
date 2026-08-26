<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorStatus;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Schemastud\DataSchemas\Http\SchemaDocumentController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-doctrine-convergence ticket 06 — the negative-space detector.
 *
 * The signal is the full live surface MINUS the declared set. A whitelist can never report absence; a set
 * difference always can. So these tests care most about what the audit says about surfaces NOBODY declared.
 */
class UndeclaredSurfaceAuditTest extends TestCase
{
    private function audit(): UndeclaredSurfaceAudit
    {
        return new UndeclaredSurfaceAudit(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );
    }

    /** @return list<string> */
    private function uris(): array
    {
        return array_map(fn (array $row) => $row['uri'], $this->audit()->undeclared());
    }

    private function rowFor(string $uri): ?array
    {
        foreach ($this->audit()->undeclared() as $row) {
            if ($row['uri'] === $uri) {
                return $row;
            }
        }

        return null;
    }

    public function test_it_enumerates_the_live_route_table_rather_than_the_generated_set(): void
    {
        // Nothing is registered in any manifest source, so a whitelist-shaped check would report zero here.
        Route::get('api/v1/orphans', [UndeclaredFixtureController::class, 'plain']);

        $this->assertContains('api/v1/orphans', $this->uris());
    }

    public function test_an_undeclared_surface_names_its_location(): void
    {
        Route::get('api/v1/orphans', [UndeclaredFixtureController::class, 'plain']);

        $row = $this->rowFor('api/v1/orphans');

        $this->assertNotNull($row);
        // A finding without a location is a score, not a work-list.
        $this->assertStringContainsString('UndeclaredSurfaceAuditTest.php:', $row['location']);
    }

    public function test_a_route_closure_produces_a_finding_rather_than_being_skipped(): void
    {
        // A closure has no resolvable action, so every static pass in the pipeline silently steps over it —
        // which is what made closures the least visible surface in the estate.
        Route::get('api/v1/closure-surface', fn () => ['data' => null]);

        $row = $this->rowFor('api/v1/closure-surface');

        $this->assertNotNull($row);
        $this->assertSame(UndeclaredSurfaceAudit::TIER_MANUAL, $row['tier']);
        $this->assertStringContainsString('closure route', $row['location']);
    }

    public function test_a_resolvable_controller_action_is_tiered_guided(): void
    {
        Route::get('api/v1/orphans', [UndeclaredFixtureController::class, 'plain']);

        $this->assertSame(UndeclaredSurfaceAudit::TIER_GUIDED, $this->rowFor('api/v1/orphans')['tier']);
    }

    public function test_a_particle_route_missing_only_its_declaration_is_tiered_mechanical(): void
    {
        // On the pipeline already, so adding the slot is a local decision-free edit — a different kind of
        // work item from "commit to an API contract", which is why the tiers exist.
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'shapeless',
            backing: 'App\\Models\\Widget',
        ));

        Route::prefix('resources')->group(fn () => Route::particleResource('shapeless', 'shapeless', ['only' => ['index']]));

        $this->assertSame(UndeclaredSurfaceAudit::TIER_MECHANICAL, $this->rowFor('resources/shapeless')['tier']);
    }

    // ── the declared set is subtracted ──────────────────────────────────────────────────────────────

    public function test_an_explicit_returns_macro_counts_as_declared(): void
    {
        $this->annotate(
            Route::get('api/v1/annotated', [UndeclaredFixtureController::class, 'plain']),
            ['returns' => WidgetGateData::class],
        );

        $this->assertNotContains('api/v1/annotated', $this->uris());
    }

    public function test_a_response_attribute_on_the_method_counts_as_declared(): void
    {
        Route::get('api/v1/attributed', [UndeclaredFixtureController::class, 'declared']);

        $this->assertNotContains('api/v1/attributed', $this->uris());
    }

    public function test_a_particle_resource_declaring_its_data_class_counts_as_declared(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: WidgetGateData::class,
        ));

        Route::prefix('resources')->group(fn () => Route::particleResource('widgets', 'widgets', ['only' => ['index']]));

        $this->assertNotContains('resources/widgets', $this->uris());
    }

    public function test_an_operation_declaring_its_output_slot_counts_as_declared(): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'widgets',
            name: 'recalculate',
            kind: OperationKind::Write,
            model: 'App\\Models\\Widget',
            handle: fn () => null,
            output: WidgetGateData::class,
        ));

        Route::prefix('resources')->group(fn () => Route::particleOp('widgets', 'widgets', 'recalculate'));

        $this->assertNotContains('resources/widgets/{id}/op/recalculate', $this->uris());
    }

    public function test_a_stream_declaration_counts_as_declared(): void
    {
        $this->annotate(
            Route::get('api/v1/watch', [UndeclaredFixtureController::class, 'plain']),
            ['streams' => ['run_status' => [WidgetGateData::class]]],
        );

        $this->assertNotContains('api/v1/watch', $this->uris());
    }

    /**
     * Stamp route-ACTION keys the way the `->returns()` / `->streams()` macros do. They set the action, not a
     * route default — using `->defaults()` here would test a declaration site that does not exist.
     */
    private function annotate(\Illuminate\Routing\Route $route, array $keys): void
    {
        $route->setAction(array_merge($route->getAction(), $keys));
    }

    // ── exceptions ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_declared_exceptions_produce_no_findings(): void
    {
        Route::get('up', fn () => 'ok');
        Route::post('broadcasting/auth', fn () => 'ok');
        Route::post('oauth/device/code', fn () => 'ok');
        Route::get('.well-known/oauth-authorization-server', fn () => 'ok');
        Route::get('sanctum/csrf-cookie', fn () => 'ok');

        $uris = $this->uris();

        foreach (['up', 'broadcasting/auth', 'oauth/device/code', '.well-known/oauth-authorization-server', 'sanctum/csrf-cookie'] as $exempt) {
            $this->assertNotContains($exempt, $uris, "[{$exempt}] must be exempt.");
        }
    }

    /**
     * Beam mounts its own OpenAPI artifact routes unconditionally (ADR-0211), so unlike every other
     * exemption here these are LIVE in the bare test app. The body is an OpenAPI document, not a
     * boundary-crossing application data shape — and it can never appear in the document it serves, since
     * the published Scribe stub extracts `api/*` only.
     */
    public function test_beams_own_openapi_artifact_routes_are_exempt(): void
    {
        $uris = $this->uris();

        $this->assertNotContains('beam/openapi.yaml', $uris);
        $this->assertNotContains('beam/openapi.json', $uris);
    }

    /**
     * The description-document rule (beam-facade ticket 114). The public schema door serves
     * `application/schema+json` — the bare artifact at its own `$id` — so it is outside the
     * invariant's extension for the same reason the OpenAPI artifact routes above are, and it is
     * listed by exact controller FQCN rather than by its `Schemastud\DataSchemas\` package prefix.
     *
     * It mounts at any host declaring an absolute authority (8 today) and was measured live in
     * `~/Herd/splicewire-app/.beam/undeclared-surface.json` as `schemas/{path}`. The URI is
     * config-derived, so only the class name is stable enough to exempt on.
     */
    public function test_the_public_schema_door_is_exempt_as_a_description_document(): void
    {
        Route::get('schemas/{path}', SchemaDocumentController::class)->where('path', '.*');

        $this->assertNotContains('schemas/{path}', $this->uris());
    }

    /**
     * The other half of the rule, and the reason it is written on the media type rather than on
     * "returns a document": markdown source bytes are application data. The `beam-mdx` authoring
     * pair is the live specimen (ticket 114's inbound) and it stays in the population.
     */
    public function test_a_document_response_that_is_application_data_stays_in_the_population(): void
    {
        Route::get('api/v1/beam/content/{name}', fn () => response('# hi', 200, ['Content-Type' => 'text/markdown']));

        $this->assertContains('api/v1/beam/content/{name}', $this->uris());
    }

    public function test_a_vendor_owned_action_is_exempt_because_we_did_not_declare_it(): void
    {
        Route::get('api/v1/vendor-mounted', '\\Laravel\\Sanctum\\Http\\Controllers\\CsrfCookieController@show');

        $this->assertNotContains('api/v1/vendor-mounted', $this->uris());
    }

    // ── invokable controllers (beam-facade ticket 124) ──────────────────────────────────────────────

    /**
     * Laravel records an invokable controller as a BARE class string with no `@method` segment, so the
     * obvious `str_contains('@')` test read it as a closure. The declaration on `__invoke` was then
     * structurally invisible — beam's own public intake door counted as negative space no matter what
     * it declared.
     */
    public function test_an_invokable_controllers_declaration_is_seen(): void
    {
        Route::post('api/v1/invokable-declared', DeclaredInvokableFixtureController::class);

        $this->assertNotContains('api/v1/invokable-declared', $this->uris());
    }

    /**
     * The other half: an undeclared invokable is still reported — and as GUIDED, not MANUAL. Manual
     * means "there is no method to annotate; a controller has to exist first", which is the one thing
     * that is certainly false about an invokable controller.
     */
    public function test_an_undeclared_invokable_is_reported_as_guided_not_manual(): void
    {
        Route::post('api/v1/invokable-bare', BareInvokableFixtureController::class);

        $row = $this->rowFor('api/v1/invokable-bare');

        $this->assertNotNull($row);
        $this->assertSame(UndeclaredSurfaceAudit::TIER_GUIDED, $row['tier']);
        $this->assertStringNotContainsString('closure route', $row['location']);
    }

    // ── reporting + determinism ─────────────────────────────────────────────────────────────────────

    public function test_it_reports_findings_through_the_doctor_vocabulary(): void
    {
        Route::get('api/v1/orphans', [UndeclaredFixtureController::class, 'plain']);

        $findings = $this->audit()->run();

        $this->assertNotEmpty($findings);
        $this->assertSame(UndeclaredSurfaceAudit::CHECK, $findings[0]->check);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
    }

    public function test_it_passes_when_every_surface_declares_its_shape(): void
    {
        // The bare test app mounts nothing, so this is the converged-repo case: quiet, not silent.
        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_running_twice_with_no_change_produces_an_identical_result(): void
    {
        Route::get('api/v1/zebra', [UndeclaredFixtureController::class, 'plain']);
        Route::get('api/v1/alpha', [UndeclaredFixtureController::class, 'plain']);
        Route::post('api/v1/alpha', [UndeclaredFixtureController::class, 'plain']);

        $first = $this->audit()->undeclared();

        $this->assertSame($first, $this->audit()->undeclared());

        // Sorted, not router-registration-ordered — otherwise the committed artifact churns in review and
        // its "the number only goes down" contract becomes unreadable.
        $ours = array_values(array_filter($first, fn (array $r) => str_starts_with($r['uri'], 'api/v1/')));
        $this->assertSame(['api/v1/alpha', 'api/v1/alpha', 'api/v1/zebra'], array_column($ours, 'uri'));
        $this->assertSame(['GET'], $ours[0]['methods']);
        $this->assertSame(['POST'], $ours[1]['methods']);
    }
}

class UndeclaredFixtureController
{
    public function plain() {}

    #[ResponseFromData(WidgetGateData::class)]
    public function declared() {}
}

class BareInvokableFixtureController
{
    public function __invoke() {}
}

class DeclaredInvokableFixtureController
{
    #[ResponseFromData(WidgetGateData::class)]
    public function __invoke() {}
}
