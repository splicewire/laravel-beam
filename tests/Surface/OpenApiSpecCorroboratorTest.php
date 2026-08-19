<?php

namespace Splicewire\Beam\Tests\Surface;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surface\Data\SeamCorroborationData;
use Splicewire\Beam\Surface\Data\SurfaceFindingData;
use Splicewire\Beam\Surface\OpenApiSpecCorroborator;
use Splicewire\Beam\Surface\RuntimeCorroborator;
use Splicewire\Beam\Surface\SpecSource;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * soc2-readiness-dogfood tickets 03 + 04 — document versus runtime, and the provenance floor.
 *
 * The findings that matter are the DISAGREEMENTS. Agreement is the boring majority and is counted only
 * so the denominator is honest; these tests spend their assertions on the diff.
 */
class OpenApiSpecCorroboratorTest extends TestCase
{
    private function corroborator(): OpenApiSpecCorroborator
    {
        return new OpenApiSpecCorroborator(new RuntimeCorroborator(
            $this->app->make(Router::class),
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
            new UndeclaredSurfaceAudit(
                $this->app->make(ParticleResourceRegistry::class),
                $this->app->make(ParticleOperationRegistry::class),
            ),
        ));
    }

    /** @param array<string, mixed> $paths */
    private function spec(array $paths, array $document = []): SpecSource
    {
        return SpecSource::fromArray(array_replace_recursive([
            'openapi' => '3.1.0',
            'info' => ['title' => 'Fixture', 'version' => '1'],
            'components' => ['securitySchemes' => ['bearerAuth' => ['type' => 'http']]],
            'paths' => $paths,
        ], $document));
    }

    /** @return list<SurfaceFindingData> */
    private function ofKind(SeamCorroborationData $result, string $kind): array
    {
        return array_values(array_filter($result->all(), fn (SurfaceFindingData $f) => $f->kind === $kind));
    }

    // ── the disagreements ───────────────────────────────────────────────────────────────────────────

    /**
     * The headline finding, from a deliberately mismatched fixture: the document promises bearer auth and
     * the router mounts the route wide open.
     */
    public function test_documented_as_authenticated_but_actually_open_is_a_disagreement(): void
    {
        Route::get('api/v1/widgets', fn () => []);

        $result = $this->corroborator()->corroborate($this->spec([
            '/api/v1/widgets' => ['get' => ['security' => [['bearerAuth' => []]]]],
        ])->inventory());

        $findings = $this->ofKind($result, SurfaceFindingData::KIND_DOCUMENTED_AUTHENTICATED_BUT_OPEN);

        $this->assertCount(1, $findings);
        $this->assertSame('GET /api/v1/widgets', $findings[0]->signature);
        $this->assertSame('bearerAuth', $findings[0]->documented);
        $this->assertSame('no authentication middleware', $findings[0]->observed);
        $this->assertContains($findings[0], $result->disagreements);
    }

    public function test_a_route_present_in_the_router_but_absent_from_the_spec_is_reported(): void
    {
        Route::get('api/v1/widgets', fn () => []);
        Route::post('api/v1/secret-drain', fn () => []);

        $result = $this->corroborator()->corroborate($this->spec([
            '/api/v1/widgets' => ['get' => ['security' => []]],
        ])->inventory());

        $signatures = array_column($this->ofKind($result, SurfaceFindingData::KIND_UNDOCUMENTED_SURFACE), 'signature');

        $this->assertContains('POST /api/v1/secret-drain', $signatures);
    }

    public function test_a_seam_documented_but_never_routed_is_reported(): void
    {
        $result = $this->corroborator()->corroborate($this->spec([
            '/api/v1/ghost' => ['get' => ['security' => []]],
        ])->inventory());

        $findings = $this->ofKind($result, SurfaceFindingData::KIND_DOCUMENTED_BUT_UNROUTED);

        $this->assertSame(['GET /api/v1/ghost'], array_column($findings, 'signature'));
    }

    /** A doc defect rather than a hole, but still a disagreement — the document is wrong either way. */
    public function test_documented_as_public_but_gated_is_a_disagreement(): void
    {
        Route::middleware('auth:sanctum')->get('api/v1/widgets', fn () => []);

        $result = $this->corroborator()->corroborate($this->spec([
            '/api/v1/widgets' => ['get' => ['security' => []]],
        ])->inventory());

        $this->assertCount(1, $this->ofKind($result, SurfaceFindingData::KIND_DOCUMENTED_PUBLIC_BUT_GATED));
    }

    public function test_an_agreeing_seam_is_an_agreement_not_a_disagreement(): void
    {
        Route::middleware('auth:sanctum')->get('api/v1/widgets', fn () => []);

        $result = $this->corroborator()->corroborate($this->spec([
            '/api/v1/widgets' => ['get' => ['security' => [['bearerAuth' => []]]]],
        ])->inventory());

        $this->assertSame([], array_column($result->disagreements, 'signature'));
        $this->assertNotEmpty($this->ofKind($result, SurfaceFindingData::KIND_AGREEMENT));
    }

    // ── omission becomes a gap, never a pass ────────────────────────────────────────────────────────

    /**
     * The ticket's load-bearing assertion. A facet the runtime could not determine must appear in `gaps`
     * and must NOT appear as an agreement — an unknowable gate is not a satisfied one.
     */
    public function test_an_omitted_facet_surfaces_as_a_gap_never_a_pass(): void
    {
        Route::middleware('auth:sanctum')->get('api/v1/widgets', fn () => []);

        $result = $this->corroborator()->corroborate($this->spec([
            '/api/v1/widgets' => ['get' => ['security' => [['bearerAuth' => []]]]],
        ])->inventory());

        $gapFacets = array_column($this->ofKind($result, SurfaceFindingData::KIND_UNDETERMINED_FACET), 'facet');

        $this->assertContains('authorization_policy', $gapFacets);
        $this->assertContains('tenant_scoped', $gapFacets);
        $this->assertContains('audit_logged', $gapFacets);

        $agreementFacets = array_column($this->ofKind($result, SurfaceFindingData::KIND_AGREEMENT), 'facet');
        $this->assertNotContains('authorization_policy', $agreementFacets);
    }

    public function test_a_path_parameter_name_mismatch_does_not_manufacture_findings(): void
    {
        // The router says `{widget}`; the document says `{id}`. Same seam.
        Route::middleware('auth:sanctum')->get('api/v1/widgets/{widget}', fn () => []);

        $result = $this->corroborator()->corroborate($this->spec([
            '/api/v1/widgets/{id}' => ['get' => ['security' => [['bearerAuth' => []]]]],
        ])->inventory());

        $this->assertSame([], $this->ofKind($result, SurfaceFindingData::KIND_UNDOCUMENTED_SURFACE));
        $this->assertSame([], $this->ofKind($result, SurfaceFindingData::KIND_DOCUMENTED_BUT_UNROUTED));
    }

    /** The undeclared-SHAPE direction is read from the existing audit rather than recomputed here. */
    public function test_the_negative_space_audits_findings_are_folded_in_as_gaps(): void
    {
        Route::get('api/v1/shapeless', fn () => []);

        $result = $this->corroborator()->corroborate($this->spec([
            '/api/v1/shapeless' => ['get' => ['security' => []]],
        ])->inventory());

        $shapes = $this->ofKind($result, SurfaceFindingData::KIND_UNDECLARED_SHAPE);

        $this->assertNotEmpty($shapes);
        $this->assertContains($shapes[0], $result->gaps);
    }

    // ── ticket 04: the provenance floor ─────────────────────────────────────────────────────────────

    public function test_a_spec_with_no_runtime_binding_still_produces_findings(): void
    {
        $result = $this->corroborator()->audit($this->spec([
            '/v2/orders' => ['get' => []],
            '/v2/orders/{id}' => ['delete' => ['security' => [['bearerAuth' => []]]]],
        ])->inventory());

        $this->assertNotSame([], $result->all());
        $this->assertSame(['GET /v2/orders'], array_column(
            $this->ofKind($result, SurfaceFindingData::KIND_UNDECLARED_SECURITY),
            'signature',
        ));
    }

    /** One vocabulary, different floor: every unbound finding is weak, and none of them can be strong. */
    public function test_every_unbound_finding_is_floored_to_the_weak_rank(): void
    {
        $result = $this->corroborator()->audit($this->spec([
            '/v2/orders' => ['get' => []],
            '/v2/orders/{id}' => ['delete' => ['security' => [['bearerAuth' => []]]]],
        ])->inventory());

        foreach ($result->all() as $finding) {
            $this->assertSame(SurfaceFindingData::RANK_DOCUMENTED, $finding->provenanceRank, $finding->kind);
            $this->assertFalse($finding->isCorroborated());
        }

        $this->assertSame(SurfaceFindingData::RANK_DOCUMENTED, $result->strongestRank());
    }

    public function test_a_corroborated_finding_reaches_the_strong_rank(): void
    {
        Route::middleware('auth:sanctum')->get('api/v1/widgets', fn () => []);

        $result = $this->corroborator()->corroborate($this->spec([
            '/api/v1/widgets' => ['get' => ['security' => [['bearerAuth' => []]]]],
        ])->inventory());

        $this->assertSame(SurfaceFindingData::RANK_OBSERVED, $result->strongestRank());
        $this->assertTrue($result->all()[0]->isCorroborated());
    }

    /** No second verdict language: both paths emit the same finding kinds off the same class. */
    public function test_both_paths_use_one_finding_vocabulary(): void
    {
        Route::get('api/v1/widgets', fn () => []);

        $bound = $this->corroborator()->corroborate($this->spec([
            '/api/v1/widgets' => ['get' => []],
        ])->inventory());
        $unbound = $this->corroborator()->audit($this->spec([
            '/api/v1/widgets' => ['get' => []],
        ])->inventory());

        $this->assertContains(
            SurfaceFindingData::KIND_UNDECLARED_SECURITY,
            array_column($bound->all(), 'kind'),
        );
        $this->assertContains(
            SurfaceFindingData::KIND_UNDECLARED_SECURITY,
            array_column($unbound->all(), 'kind'),
        );
    }

    /**
     * An unbound audit must be DISTINGUISHABLE from a passing corroborated one — otherwise "we could not
     * look" quietly reads as "we looked and it was fine", which is the whole trap ticket 04 exists to
     * avoid. The mode is on the envelope, so the distinction survives being skimmed.
     */
    public function test_an_unbound_audit_is_distinguishable_from_a_clean_corroborated_one(): void
    {
        Route::middleware('auth:sanctum')->get('api/v1/widgets', fn () => []);
        $paths = ['/api/v1/widgets' => ['get' => ['security' => [['bearerAuth' => []]]]]];

        $bound = $this->corroborator()->corroborate($this->spec($paths)->inventory());
        $unbound = $this->corroborator()->audit($this->spec($paths)->inventory());

        $this->assertSame(SeamCorroborationData::MODE_CORROBORATED, $bound->mode);
        $this->assertSame(SeamCorroborationData::MODE_SPEC_ONLY, $unbound->mode);
        $this->assertTrue($bound->isCorroborated());
        $this->assertFalse($unbound->isCorroborated());
        $this->assertNotSame($bound->strongestRank(), $unbound->strongestRank());
        $this->assertSame(0, $unbound->runtimeRouteCount);
    }

    /** A security scheme a document names but never defines is a defect readable without a runtime. */
    public function test_an_unresolvable_scheme_is_a_disagreement_in_the_unbound_path(): void
    {
        $result = $this->corroborator()->audit($this->spec([
            '/v2/orders' => ['get' => ['security' => [['ghostAuth' => []]]]],
        ])->inventory());

        $findings = $this->ofKind($result, SurfaceFindingData::KIND_UNRESOLVABLE_GATE);

        $this->assertSame(['GET /v2/orders'], array_column($findings, 'signature'));
        $this->assertContains($findings[0], $result->disagreements);
    }
}
