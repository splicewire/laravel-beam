<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\InertiaPropShapeAudit;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-doctrine-convergence ticket 06 — the INERTIA leg of the negative-space detector.
 *
 * Every source fixture here is a heredoc rather than a real fixture file, for one reason worth stating: a
 * sibling audit ({@see CentralPinJustificationAudit}) excludes `/tests/` and
 * `/fixtures/` wholesale, which means an audit asserted from inside a test tree can pass BY EXCLUSION and
 * prove nothing. This audit carries no path exclusions at all, and `sitesInSource()` is pure over source, so
 * these tests exercise the real classifier. {@see test_it_fires_on_a_real_filesystem_scan()} closes the loop by
 * scanning an actual directory and proving the audit CAN fire through the disk path too.
 */
class InertiaPropShapeAuditTest extends TestCase
{
    private function audit(): InertiaPropShapeAudit
    {
        return new InertiaPropShapeAudit([]);
    }

    /** @return list<array<string, mixed>> */
    private function sites(string $source): array
    {
        return $this->audit()->sitesInSource($source, '/app/Http/Controllers/Fixture.php');
    }

    // ── shape 1: the ordinary inline prop array ──────────────────────────────────────────────────────

    public function test_an_inline_prop_array_is_a_finding_tiered_guided(): void
    {
        // The representative worst case, after audiostud's StudioController::show(): raw scalars and arrays,
        // no declared shape anywhere. Somebody has to design the page-props class, so it is not mechanical.
        $sites = $this->sites(<<<'PHP'
        <?php
        class StudioController
        {
            public function show(Request $request): Response
            {
                return Inertia::render('studio', [
                    'studioBody' => $this->studioBody(),
                    'antennaEnabled' => $client->enabled(),
                    'previewEnabled' => true,
                    'initialSong' => $this->templateSong(),
                    'lyricPieces' => [],
                ]);
            }
        }
        PHP);

        $this->assertCount(1, $sites);
        $this->assertSame('studio', $sites[0]['page']);
        $this->assertSame(UndeclaredSurfaceAudit::TIER_GUIDED, $sites[0]['tier']);
        $this->assertSame(InertiaPropShapeAudit::REASON_INLINE_ARRAY, $sites[0]['reason']);
        $this->assertSame(InertiaPropShapeAudit::CONTEXT_METHOD, $sites[0]['context']);
        $this->assertSame('StudioController::show()', $sites[0]['enclosing']);
    }

    public function test_a_finding_names_its_location_as_file_and_line(): void
    {
        // A finding without a location is a score, not a work-list.
        $sites = $this->sites(<<<'PHP'
        <?php
        $x = 1;
        $y = 2;
        return Inertia::render('studio', ['a' => 1]);
        PHP);

        $this->assertSame('/app/Http/Controllers/Fixture.php', $sites[0]['file']);
        $this->assertSame(4, $sites[0]['line']);
    }

    public function test_the_inertia_helper_function_is_the_same_surface_as_the_facade(): void
    {
        $sites = $this->sites("<?php return inertia('studio', ['a' => 1]);");

        $this->assertCount(1, $sites);
        $this->assertSame(InertiaPropShapeAudit::REASON_INLINE_ARRAY, $sites[0]['reason']);
    }

    public function test_props_passed_by_name_are_not_mistaken_for_no_props(): void
    {
        // Reading the props slot positionally would classify this as propless — a false PASS, the one
        // direction this audit must never fail in.
        $sites = $this->sites("<?php return Inertia::render('studio', props: ['a' => 1]);");

        $this->assertCount(1, $sites);
        $this->assertSame('studio', $sites[0]['page']);
        $this->assertSame(InertiaPropShapeAudit::REASON_INLINE_ARRAY, $sites[0]['reason']);
    }

    // ── the Data-derived-but-FLATTENED case ──────────────────────────────────────────────────────────

    public function test_data_objects_flattened_into_an_array_are_still_undeclared_but_tiered_mechanical(): void
    {
        // audiostud's SongController::show(). A Data class IS involved, but what crosses the boundary is an
        // array — declaredness is a property of the SITE, not of the ancestry of the values at it. The tier
        // differs because every field type is already written down, so hoisting is decision-free.
        $sites = $this->sites(<<<'PHP'
        <?php
        class SongController
        {
            public function show(): Response
            {
                return Inertia::render('songs/show', [
                    'song' => PublicSongData::project($song),
                ]);
            }
        }
        PHP);

        $this->assertCount(1, $sites);
        $this->assertSame(InertiaPropShapeAudit::REASON_FLATTENED_DATA, $sites[0]['reason']);
        $this->assertSame(UndeclaredSurfaceAudit::TIER_MECHANICAL, $sites[0]['tier']);
    }

    public function test_one_raw_value_alongside_data_objects_drops_the_row_back_to_guided(): void
    {
        // SongSharingServiceProvider's shape. `sharedViaLink` has no declared field, so somebody must design
        // it and the edit stops being decision-free.
        $sites = $this->sites(<<<'PHP'
        <?php
        return Inertia::render('songs/shared', [
            'song' => SongProjectData::project($composition),
            'sharedViaLink' => true,
        ]);
        PHP);

        $this->assertSame(InertiaPropShapeAudit::REASON_INLINE_ARRAY, $sites[0]['reason']);
        $this->assertSame(UndeclaredSurfaceAudit::TIER_GUIDED, $sites[0]['tier']);
    }

    public function test_a_spread_into_the_prop_array_is_not_optimistically_read_as_all_data(): void
    {
        $sites = $this->sites(<<<'PHP'
        <?php
        return Inertia::render('songs/show', [
            'song' => PublicSongData::project($song),
            ...$more,
        ]);
        PHP);

        $this->assertSame(InertiaPropShapeAudit::REASON_INLINE_ARRAY, $sites[0]['reason']);
    }

    // ── shape 2: renders inside closures (double-invisible) ──────────────────────────────────────────

    public function test_renders_inside_closures_are_caught_and_name_the_call_they_were_passed_to(): void
    {
        // audiostud's FortifyServiceProvider::configureViews() — 7 of these in one file. Double-invisible:
        // no controller action to find them by, and inline props once found. Every action-resolving pass in
        // the pipeline steps straight over them.
        $sites = $this->sites(<<<'PHP'
        <?php
        class FortifyServiceProvider
        {
            private function configureViews(): void
            {
                Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
                    'canResetPassword' => true,
                    'status' => $request->session()->get('status'),
                ]));

                Fortify::registerView(function () {
                    return Inertia::render('auth/register', [
                        'passwordRules' => Password::defaults()->toPasswordRulesString(),
                    ]);
                });
            }
        }
        PHP);

        $this->assertCount(2, $sites);

        $this->assertSame('auth/login', $sites[0]['page']);
        $this->assertSame(InertiaPropShapeAudit::CONTEXT_CLOSURE, $sites[0]['context']);
        // The closure has no name, so the label is the call it was passed to — the string an author greps for.
        $this->assertSame('Fortify::loginView()', $sites[0]['enclosing']);

        $this->assertSame('auth/register', $sites[1]['page']);
        $this->assertSame(InertiaPropShapeAudit::CONTEXT_CLOSURE, $sites[1]['context']);
        $this->assertSame('Fortify::registerView()', $sites[1]['enclosing']);
    }

    public function test_a_closure_render_is_tiered_by_prop_shape_not_downgraded_to_manual(): void
    {
        // A deliberate divergence from the HTTP leg. There, `manual` means there is literally nothing to
        // annotate. Here the declaration site is the props ARGUMENT, which exists and is writable inside a
        // closure just as it is inside a method. Closure-ness changes how hard the surface is to FIND; the
        // tier tracks how hard it is to CONVERT.
        $sites = $this->sites(<<<'PHP'
        <?php
        app(GuestTokenScopes::class)->handle('composition', function ($link, $uuid) {
            return Inertia::render('songs/shared', ['song' => SongProjectData::project($c)]);
        });
        PHP);

        $this->assertSame(InertiaPropShapeAudit::CONTEXT_CLOSURE, $sites[0]['context']);
        $this->assertSame('->handle()', $sites[0]['enclosing']);
        $this->assertNotSame(UndeclaredSurfaceAudit::TIER_MANUAL, $sites[0]['tier']);
        $this->assertSame(UndeclaredSurfaceAudit::TIER_MECHANICAL, $sites[0]['tier']);
    }

    public function test_the_closure_is_reported_rather_than_its_outer_method(): void
    {
        // Reporting `configureViews()` here would hide the very thing worth naming.
        $sites = $this->sites(<<<'PHP'
        <?php
        class P
        {
            public function boot(): void
            {
                Fortify::loginView(fn () => Inertia::render('auth/login', ['a' => 1]));
            }
        }
        PHP);

        $this->assertSame('Fortify::loginView()', $sites[0]['enclosing']);
    }

    // ── shape 3: Route::inertia — declared by emptiness ──────────────────────────────────────────────

    public function test_a_propless_route_inertia_is_not_a_finding(): void
    {
        // `Route::inertia('/os', 'os')` passes no data. There is no shape to declare and no undeclared shape
        // can be hiding in one: "there is nothing to ask about", not "we never asked". Same asymmetry the MCP
        // leg had to get right — absence is a finding only where something MUST exist, and props need not.
        $source = <<<'PHP'
        <?php
        Route::inertia('/os', 'os')->middleware(['auth'])->name('os.shell');
        Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
        PHP;

        $sites = $this->sites($source);

        $this->assertCount(2, $sites);

        // Recorded, tier-less and reason-less — countable, so the cap is never silent.
        foreach ($sites as $site) {
            $this->assertNull($site['reason']);
            $this->assertNull($site['tier']);
        }

        // And it never reaches the work-list.
        $this->assertSame([], array_values(array_filter($sites, fn (array $r) => $r['reason'] !== null)));
    }

    public function test_a_route_inertia_that_does_pass_props_is_a_finding_at_the_shifted_props_slot(): void
    {
        // The props slot is index 2 here because the uri comes first. Reading it positionally as index 1
        // would classify the PAGE NAME as an unresolvable props expression.
        $sites = $this->sites("<?php Route::inertia('/os', 'os', ['shell' => 'full']);");

        $this->assertCount(1, $sites);
        $this->assertSame('os', $sites[0]['page']);
        $this->assertSame(InertiaPropShapeAudit::REASON_INLINE_ARRAY, $sites[0]['reason']);
    }

    public function test_a_propless_or_empty_render_is_likewise_not_a_finding(): void
    {
        $sites = $this->sites(<<<'PHP'
        <?php
        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
        return Inertia::render('auth/two-factor-challenge', []);
        PHP);

        $this->assertCount(2, $sites);
        $this->assertNull($sites[0]['reason']);
        $this->assertNull($sites[1]['reason']);
    }

    // ── the clean case ───────────────────────────────────────────────────────────────────────────────

    public function test_a_render_passing_a_single_data_object_is_not_a_finding_at_all(): void
    {
        // The converged shape: one typed shape crosses the boundary, so the site declares itself and is not
        // even recorded as a site.
        $this->assertSame([], $this->sites(<<<'PHP'
        <?php
        class SongController
        {
            public function show(): Response
            {
                return Inertia::render('songs/show', SongPageData::from($song));
            }
        }
        PHP));

        $this->assertSame([], $this->sites("<?php return Inertia::render('studio', new StudioPageData(\$song));"));
    }

    public function test_an_unresolvable_props_expression_is_reported_rather_than_dropped(): void
    {
        // beam-accounts' SecurityController does exactly this. Genuinely can't tell — and an unreadable
        // boundary is precisely the "generated nothing" / "does not exist" collapse the ticket exists to
        // make visible, so it is surfaced, not silently passed.
        $sites = $this->sites("<?php return Inertia::render('settings/security', \$props);");

        $this->assertCount(1, $sites);
        $this->assertSame(InertiaPropShapeAudit::REASON_UNRESOLVABLE, $sites[0]['reason']);
        $this->assertSame(UndeclaredSurfaceAudit::TIER_GUIDED, $sites[0]['tier']);
    }

    public function test_a_dynamic_page_name_still_produces_a_located_finding(): void
    {
        // beam-mdx's ContentRoutes renders `$page`. The page name is unknown; the file:line is not.
        $sites = $this->sites("<?php return Inertia::render(\$page, ['slug' => \$name]);");

        $this->assertNull($sites[0]['page']);
        $this->assertSame(1, $sites[0]['line']);
    }

    public function test_an_unrelated_static_call_is_not_a_render_site(): void
    {
        $this->assertSame([], $this->sites("<?php Inertia::share('a', 1); Route::get('/x', \$h); Other::render('p', ['a' => 1]);"));
    }

    // ── reporting, determinism, and firing through the disk path ─────────────────────────────────────

    public function test_it_reports_findings_through_the_doctor_vocabulary_naming_the_closure(): void
    {
        $root = $this->plant(<<<'PHP'
        <?php
        Fortify::loginView(fn () => Inertia::render('auth/login', ['canRegister' => true]));
        PHP);

        $findings = (new InertiaPropShapeAudit([$root]))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(InertiaPropShapeAudit::CHECK, $findings[0]->check);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('auth/login', $findings[0]->detail);
        $this->assertStringContainsString('in a closure — Fortify::loginView()', $findings[0]->detail);
        $this->assertStringContainsString(UndeclaredSurfaceAudit::TIER_GUIDED, $findings[0]->detail);
    }

    public function test_it_fires_on_a_real_filesystem_scan(): void
    {
        // The point of this test: prove the audit CAN fire through the disk path. An audit whose scan is
        // silently filtered to nothing passes every assertion about what it does not report.
        $root = $this->plant("<?php return Inertia::render('studio', ['a' => 1]);");

        $audit = new InertiaPropShapeAudit([$root]);

        $this->assertCount(1, $audit->undeclared());
        $this->assertStringEndsWith('/planted.php', $audit->undeclared()[0]['file']);
    }

    public function test_an_empty_directory_in_the_scan_root_does_not_break_the_sweep(): void
    {
        // RecursiveIteratorIterator yields an empty directory as a LEAF, so the extension filter never sees
        // it and without the isFile() guard it reaches file_get_contents() as a directory.
        $root = $this->plant("<?php return Inertia::render('studio', ['a' => 1]);");
        mkdir($root.'/empty', 0777, true);

        $this->assertCount(1, (new InertiaPropShapeAudit([$root]))->undeclared());
    }

    public function test_it_passes_with_a_propless_census_when_nothing_is_undeclared(): void
    {
        $root = $this->plant("<?php Route::inertia('/os', 'os');");

        $findings = (new InertiaPropShapeAudit([$root]))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        // Quiet, not silent: the propless sites are counted in the pass message.
        $this->assertStringContainsString('1 propless site(s)', $findings[0]->detail);
    }

    public function test_running_twice_with_no_change_produces_an_identical_sorted_result(): void
    {
        $root = $this->plant("<?php return Inertia::render('studio', ['a' => 1]);");
        file_put_contents($root.'/zebra.php', "<?php return Inertia::render('zebra', ['a' => 1]);");
        file_put_contents($root.'/alpha.php', "<?php return Inertia::render('alpha', ['a' => 1]);");

        $audit = new InertiaPropShapeAudit([$root]);
        $first = $audit->undeclared();

        $this->assertSame($first, (new InertiaPropShapeAudit([$root]))->undeclared());
        // Sorted by file then line, not by filesystem iteration order — the artifact contract depends on it.
        $this->assertSame(['alpha', 'studio', 'zebra'], array_column($first, 'page'));
    }

    public function test_it_is_registered_advisory_so_it_reaches_the_surgeon_audit_json(): void
    {
        // Registration through the doctor manifest is HOW this leg reaches `surgeon:audit`'s JSON output —
        // the ticket's own acceptance criterion. Advisory (`gate: false`) matches both other legs: converting
        // a prop array into a declared page-props class is an API-contract commitment, so it is a burn-down
        // work-list, not something that should block a build.
        $registrations = $this->app->make(BeamDoctorManifest::class)->registrations();

        $ours = array_values(array_filter(
            $registrations,
            fn ($registration) => $registration->audit === InertiaPropShapeAudit::class,
        ));

        $this->assertCount(1, $ours, 'The Inertia leg must be registered exactly once.');
        $this->assertFalse($ours[0]->gate, 'The Inertia leg is advisory, matching the HTTP and MCP legs.');
        $this->assertSame('splicewire/laravel-beam', $ours[0]->package);

        // And the binding resolves, so the sweep's `$app->make($audit)` reaches a real audit rather than
        // throwing halfway through the run.
        $this->assertInstanceOf(InertiaPropShapeAudit::class, $this->app->make(InertiaPropShapeAudit::class));
    }

    /** Write one PHP file into a fresh temp root OUTSIDE the package tree, and return the root. */
    private function plant(string $source): string
    {
        $root = sys_get_temp_dir().'/beam-inertia-'.bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        file_put_contents($root.'/planted.php', $source);

        return $root;
    }
}
