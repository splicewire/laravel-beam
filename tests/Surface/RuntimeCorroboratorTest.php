<?php

namespace Splicewire\Beam\Tests\Surface;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surface\Data\RoutePostureData;
use Splicewire\Beam\Surface\PostureFacet;
use Splicewire\Beam\Surface\RuntimeCorroborator;
use Splicewire\Beam\Surface\SurfaceSignature;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * soc2-readiness-dogfood ticket 03 — posture projected off the live router.
 *
 * The assertions that matter are about **omission**. A facet the projector cannot determine must be
 * absent from the projection, because an absent facet becomes a gap downstream while a `false` becomes a
 * violation and a `true` becomes a clean bill of health. Getting that wrong in either direction is worse
 * than reporting nothing.
 */
class RuntimeCorroboratorTest extends TestCase
{
    private function corroborator(): RuntimeCorroborator
    {
        return new RuntimeCorroborator(
            $this->app->make(Router::class),
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
            new UndeclaredSurfaceAudit(
                $this->app->make(ParticleResourceRegistry::class),
                $this->app->make(ParticleOperationRegistry::class),
            ),
        );
    }

    private function postureFor(string $signature): ?RoutePostureData
    {
        return $this->corroborator()->posture()[SurfaceSignature::normalize($signature)] ?? null;
    }

    public function test_an_authenticated_route_reports_auth_required(): void
    {
        Route::middleware('auth:sanctum')->get('api/v1/guarded', fn () => []);

        $this->assertTrue($this->postureFor('GET /api/v1/guarded')->facet(PostureFacet::AuthRequired));
    }

    /**
     * Absence of auth middleware IS evidence: the resolved middleware stack is the whole truth about
     * middleware, so `false` here is a determination, not a default.
     */
    public function test_an_open_route_reports_auth_required_false(): void
    {
        Route::get('api/v1/open', fn () => []);

        $this->assertFalse($this->postureFor('GET /api/v1/open')->facet(PostureFacet::AuthRequired));
    }

    /** Auth inherited from a middleware GROUP must resolve, or every grouped route reads as open. */
    public function test_group_middleware_is_resolved_not_reported_as_a_group_name(): void
    {
        $this->app->make(Router::class)->middlewareGroup('secured', ['auth:sanctum', 'throttle:api']);

        Route::middleware('secured')->get('api/v1/grouped', fn () => []);

        $posture = $this->postureFor('GET /api/v1/grouped');

        $this->assertTrue($posture->facet(PostureFacet::AuthRequired));
        $this->assertTrue($posture->facet(PostureFacet::RateLimited));
    }

    /**
     * The regression the first real run produced. An application mounts its OWN subclass of the
     * framework's auth middleware (to override `redirectTo()`/`unauthenticated()`), so name-only
     * matching reports a fully authenticated API as wide open — 320 of 321 seams, in the run that
     * caught this.
     */
    public function test_a_host_subclass_of_the_auth_middleware_still_proves_authentication(): void
    {
        Route::middleware(HostAuthenticateFixture::class.':sanctum')->get('api/v1/subclassed', fn () => []);

        $this->assertTrue($this->postureFor('GET /api/v1/subclassed')->facet(PostureFacet::AuthRequired));
    }

    /** The inverse: an unrelated middleware whose name merely resembles one must not count. */
    public function test_an_unrelated_middleware_does_not_prove_authentication(): void
    {
        Route::middleware(NotAuthenticationFixture::class)->get('api/v1/lookalike', fn () => []);

        $this->assertFalse($this->postureFor('GET /api/v1/lookalike')->facet(PostureFacet::AuthRequired));
    }

    public function test_a_can_middleware_proves_an_authorization_gate(): void
    {
        Route::middleware('can:view,widget')->get('api/v1/gated', fn () => []);

        $this->assertTrue($this->postureFor('GET /api/v1/gated')->facet(PostureFacet::AuthorizationPolicy));
    }

    /**
     * Off the particle pipeline and with no `can:`, a gate may live in a FormRequest or in the action
     * body — invisible to any static walk. So the facet is OMITTED, and it surfaces as a gap rather than
     * as a silent pass.
     */
    public function test_an_undeterminable_authorization_facet_is_omitted_never_defaulted(): void
    {
        Route::get('api/v1/unknowable', fn () => []);

        $posture = $this->postureFor('GET /api/v1/unknowable');

        $this->assertArrayNotHasKey(PostureFacet::AuthorizationPolicy->value, $posture->facets);
        $this->assertNull($posture->facet(PostureFacet::AuthorizationPolicy));
        $this->assertContains(PostureFacet::AuthorizationPolicy, $posture->undeterminedFacets());
    }

    /**
     * On the pipeline the registry answers authoritatively in BOTH directions — a declared `ability:`
     * proves a gate, and its deliberate absence proves the lack of one.
     */
    public function test_a_particle_operation_ability_is_determinable_in_both_directions(): void
    {
        $operations = $this->app->make(ParticleOperationRegistry::class);
        $operations->register(new ParticleOperation(
            resource: 'widgets',
            name: 'gated',
            kind: OperationKind::Read,
            model: 'App\\Models\\Widget',
            handle: fn () => null,
            ability: 'update',
        ));
        $operations->register(new ParticleOperation(
            resource: 'widgets',
            name: 'ungated',
            kind: OperationKind::Read,
            model: 'App\\Models\\Widget',
            handle: fn () => null,
        ));

        Route::particleOps('widgets', 'widgets', ['gated', 'ungated']);

        $gated = $this->postureFor('POST /widgets/{id}/op/gated');
        $ungated = $this->postureFor('POST /widgets/{id}/op/ungated');

        $this->assertNotNull($gated, 'the gated op route was not mounted');
        $this->assertTrue($gated->facet(PostureFacet::AuthorizationPolicy));
        $this->assertSame('update', $gated->ability);

        $this->assertFalse($ungated->facet(PostureFacet::AuthorizationPolicy));
    }

    /**
     * Tenancy is determinable only POSITIVELY: beam's own doctrine is that the model doesn't know it's
     * tenanted, the connection does — so no tenancy middleware proves nothing at all.
     */
    public function test_tenancy_is_determinable_only_positively(): void
    {
        Route::get('api/v1/central', fn () => []);

        $this->assertArrayNotHasKey(
            PostureFacet::TenantScoped->value,
            $this->postureFor('GET /api/v1/central')->facets,
        );
    }

    public function test_audit_logging_is_determinable_only_positively(): void
    {
        Route::get('api/v1/unlogged', fn () => []);

        $this->assertArrayNotHasKey(
            PostureFacet::AuditLogged->value,
            $this->postureFor('GET /api/v1/unlogged')->facets,
        );
    }

    public function test_a_resource_key_is_carried_so_a_finding_is_a_work_list_entry(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            policy: 'widget',
        ));

        Route::particleResource('widgets', 'widgets', ['only' => ['index']]);

        $posture = $this->postureFor('GET /widgets');

        $this->assertSame('widgets', $posture->resourceKey);
        $this->assertTrue($posture->facet(PostureFacet::AuthorizationPolicy));
    }

    /** The negative-space direction is READ from the audit that owns it, not re-derived here. */
    public function test_the_undeclared_shape_direction_comes_from_the_existing_audit(): void
    {
        Route::get('api/v1/shapeless', fn () => []);

        $uris = array_column($this->corroborator()->undeclared(), 'uri');

        $this->assertContains('api/v1/shapeless', $uris);
    }

    /** A route answering several verbs is several seams; a spec describes each one separately. */
    public function test_each_http_verb_is_its_own_posture_row(): void
    {
        Route::match(['get', 'post'], 'api/v1/multi', fn () => []);

        $postures = $this->corroborator()->posture();

        $this->assertArrayHasKey('GET /api/v1/multi', $postures);
        $this->assertArrayHasKey('POST /api/v1/multi', $postures);
    }
}

/** A host's own auth middleware — the shape almost every real application actually mounts. */
class HostAuthenticateFixture extends Authenticate
{
    protected function redirectTo($request): ?string
    {
        return null;
    }
}

/** Named to look adjacent, related to nothing. */
class NotAuthenticationFixture
{
    public function handle($request, \Closure $next)
    {
        return $next($request);
    }
}
