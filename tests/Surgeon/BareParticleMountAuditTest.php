<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\BareParticleMountAudit;

/**
 * The bare-mount audit (api-surface-coherence ticket 93). Two halves, both pure — no disk, no
 * container, no route table:
 *
 * - `sitesIn()` exercises the real AST classification off heredoc source. The load-bearing assertions
 *   are the NEGATIVE ones: the macro DEFINITIONS, the ~180 comment/docblock mentions, and the config
 *   prose that make up the gap between ticket 93's 300 textual hits and its ~120 executing call sites
 *   must all be invisible here, or the audit reports a backlog three times its real size.
 * - `findingsFor()` proves each of the six macros names the right `Particle::` front door, and that a
 *   clean scan is a PASS rather than silence.
 */
class BareParticleMountAuditTest extends TestCase
{
    private function audit(): BareParticleMountAudit
    {
        // scanDirs unused by sitesIn()/findingsFor() (pure-unit path).
        return new BareParticleMountAudit('/nonexistent');
    }

    // ── sitesIn(): what the AST pass sees ───────────────────────────────────────────────────────────

    public function test_an_executing_macro_call_is_a_site(): void
    {
        $source = <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route;

        Route::particleOp('beam-ux-entries', 'beam-ux-entry', 'body', ['method' => 'get']);
        PHP;

        $sites = $this->audit()->sitesIn($source);

        $this->assertCount(1, $sites);
        $this->assertSame('particleOp', $sites[0]['macro']);
        $this->assertSame(4, $sites[0]['line']);
    }

    public function test_all_six_macros_are_detected(): void
    {
        $source = <<<'PHP'
        <?php
        Route::particleResource('fragments');
        Route::particleOp('fragments', 'fragment', 'reorder');
        Route::particleOps('fragments', 'fragment', ['reorder']);
        Route::particleRelative('fragments', Fragment::class, 'media', fn () => null);
        Route::resourceRenderings('fragments', 'fragment');
        Route::resourceFilters('fragments');
        PHP;

        $this->assertSame(
            ['particleResource', 'particleOp', 'particleOps', 'particleRelative', 'resourceRenderings', 'resourceFilters'],
            array_column($this->audit()->sitesIn($source), 'macro'),
        );
    }

    public function test_the_fully_qualified_facade_spelling_is_a_site(): void
    {
        $source = <<<'PHP'
        <?php
        \Illuminate\Support\Facades\Route::particleResource('fragments');
        PHP;

        $this->assertCount(1, $this->audit()->sitesIn($source));
    }

    public function test_the_macro_definition_is_not_a_site(): void
    {
        // `BootsParticleRouteMacros` itself: the macro name is a STRING argument, never a called method.
        $source = <<<'PHP'
        <?php
        Route::macro('particleOp', function (string $uri, string $resourceKey, string $op, array $options = []) {
            app(ParticleMounter::class)->op($this, $uri, $resourceKey, $op, $options);
        });
        PHP;

        $this->assertSame([], $this->audit()->sitesIn($source));
    }

    public function test_comments_and_docblocks_and_prose_strings_are_not_sites(): void
    {
        $source = <<<'PHP'
        <?php
        /**
         * `Route::particleResource(...)` routes off the LIVE route table and derives each read.
         */
        // The read mounts GET: `particleOp` defaults to POST regardless of kind.
        $prose = 'Route::resourceFilters() publishes nine filter routes.';
        PHP;

        $this->assertSame([], $this->audit()->sitesIn($source));
    }

    /**
     * The regression for the one call site every `Route::`-keyed search in the estate missed —
     * `BeamRouteProxy::mountFilterSubSurface()` imports the facade as `RouteFacade`, and deleting the
     * macros without seeing it would have fataled the `->beam()` filter sub-surface at runtime.
     */
    public function test_an_aliased_route_facade_import_is_a_site(): void
    {
        $source = <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route as RouteFacade;

        RouteFacade::resourceFilters(resource: $resourceKey, at: 'widgets', names: 'widgets');
        PHP;

        $sites = $this->audit()->sitesIn($source);

        $this->assertCount(1, $sites);
        $this->assertSame('resourceFilters', $sites[0]['macro']);
    }

    public function test_an_unrelated_alias_is_not_a_site(): void
    {
        // Only an alias OF the Route facade counts — a same-named alias of something else does not.
        $source = <<<'PHP'
        <?php
        use App\Support\NotTheRouter as RouteFacade;

        RouteFacade::resourceFilters(resource: 'widgets');
        PHP;

        $this->assertSame([], $this->audit()->sitesIn($source));
    }

    public function test_a_macro_name_on_another_class_is_not_a_site(): void
    {
        $source = <<<'PHP'
        <?php
        SomethingElse::particleResource('fragments');
        PHP;

        $this->assertSame([], $this->audit()->sitesIn($source));
    }

    public function test_a_particle_front_door_call_is_not_a_site(): void
    {
        $source = <<<'PHP'
        <?php
        use Splicewire\Beam\Facades\Particle;

        Particle::mount('fragments')->ops(true);
        Particle::ops('beam-ux-entries', 'beam-ux-entry', 'body', ['method' => 'get']);
        PHP;

        $this->assertSame([], $this->audit()->sitesIn($source));
    }

    // ── findingsFor(): the mapping and the clean case ───────────────────────────────────────────────

    public function test_a_site_warns_and_names_its_front_door(): void
    {
        $findings = $this->audit()->findingsFor([
            ['macro' => 'particleOp', 'file' => '/app/routes/web.php', 'line' => 43],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertSame(BareParticleMountAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('/app/routes/web.php:43', $findings[0]->detail);
        $this->assertStringContainsString('Route::particleOp()', $findings[0]->detail);
        $this->assertStringContainsString('Particle::ops()', $findings[0]->detail);
    }

    /**
     * The singular macro's front door is the PLURAL verb — there is no `Particle::op()`. Nor is the
     * target `mount(…)->only([])->ops(…)`, which still publishes the filter sub-surface: `only` gates
     * the CRUD verbs, `filters` is a separate opt-out, and only `->only([])->filters(false)->ops(…)`
     * emits the same table as the verb. A footgun, not an impossibility.
     */
    public function test_the_singular_op_macro_maps_to_the_plural_verb(): void
    {
        $this->assertSame('ops', BareParticleMountAudit::MACRO_FRONT_DOORS['particleOp']);
        $this->assertSame('ops', BareParticleMountAudit::MACRO_FRONT_DOORS['particleOps']);
    }

    public function test_every_macro_maps_to_a_front_door(): void
    {
        $this->assertSame([
            'particleResource' => 'mount',
            'particleOp' => 'ops',
            'particleOps' => 'ops',
            'particleRelative' => 'relative',
            'resourceRenderings' => 'renderings',
            'resourceFilters' => 'filters',
        ], BareParticleMountAudit::MACRO_FRONT_DOORS);
    }

    public function test_a_clean_scan_passes_rather_than_saying_nothing(): void
    {
        $findings = $this->audit()->findingsFor([]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(BareParticleMountAudit::CHECK, $findings[0]->check);
    }
}
