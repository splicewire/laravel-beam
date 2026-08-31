<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Surgeon\DuplicateRouteNameAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * The duplicate-route-name guard on the assembled table that `PendingParticleMount`'s docblock prescribes.
 *
 * The fixtures here are built by MOUNTING routes on the live router rather than by faking a collection,
 * because the whole argument for this audit is that only the assembled table can see the defect. A
 * hand-built list of name strings would pass every assertion below while testing nothing about the
 * substrate — and the second of the two recorded collisions was precisely a hand-written `Route::get()`
 * that no builder could have seen.
 *
 * Registration ORDER is load-bearing and is asserted directly: Laravel's name table is last-wins, so which
 * route is named as the winner is a claim about behaviour, not formatting.
 */
class DuplicateRouteNameAuditTest extends TestCase
{
    private function audit(): DuplicateRouteNameAudit
    {
        return new DuplicateRouteNameAudit($this->app->make(Router::class));
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function of(array $findings, string $check): array
    {
        return array_values(array_filter($findings, fn (Finding $f) => $f->check === $check));
    }

    // ── the naming requirement ──────────────────────────────────────────────────────────────────────

    /**
     * `~/Herd/audiostud`, reconstructed: an Inertia page route and a beam particle mount both claiming
     * `songs.index`. The assertion is that BOTH claimants and their URIs appear — a "1 duplicate route
     * name" count would be the FrameManifestAudit failure one level up, and there is nothing a reader can
     * do with it.
     */
    public function test_it_names_every_claimant_rather_than_counting(): void
    {
        Route::get('resources/songs', fn () => null)->name('songs.index');
        Route::get('account/songs', 'App\\Http\\Controllers\\SongPageController@index')->name('songs.index');

        $warnings = $this->of($this->audit()->run(), DuplicateRouteNameAudit::CHECK_DUPLICATE);

        $this->assertCount(1, $warnings);
        $this->assertSame(DoctorStatus::Warn, $warnings[0]->status);
        $this->assertStringContainsString('[songs.index]', $warnings[0]->detail);
        $this->assertStringContainsString('/resources/songs', $warnings[0]->detail);
        $this->assertStringContainsString('/account/songs', $warnings[0]->detail);
    }

    /**
     * The winner is the LAST route registered, and the audit says which one it is. Getting this backwards
     * would send a reader to delete the route that is actually serving `route()` lookups.
     */
    public function test_it_names_the_last_registered_route_as_the_winner(): void
    {
        Route::get('first/path', fn () => null)->name('collides');
        Route::get('second/path', fn () => null)->name('collides');

        $detail = $this->of($this->audit()->run(), DuplicateRouteNameAudit::CHECK_DUPLICATE)[0]->detail;

        $this->assertMatchesRegularExpression(
            "/route\\('collides'\\) resolves to GET \\/second\\/path/",
            $detail,
        );
        $this->assertStringContainsString('/first/path → Closure is unreachable by name', $detail);
    }

    /**
     * A closure claimant is named as a closure. The second recorded collision on this map was exactly
     * that — a `Route::get()` whose closure never served a request while its name won the flat table — so
     * an audit that could only describe controller actions would be blind to the half that motivated it.
     */
    public function test_a_closure_claimant_is_reported_as_a_closure(): void
    {
        Route::get('api/v1/search', 'App\\Http\\Controllers\\SearchController@index')->name('search');
        Route::get('search', fn () => null)->name('search');

        $detail = $this->of($this->audit()->run(), DuplicateRouteNameAudit::CHECK_DUPLICATE)[0]->detail;

        $this->assertStringContainsString('SearchController@index', $detail);
        $this->assertStringContainsString('Closure', $detail);
    }

    /** Every colliding NAME gets its own row. */
    public function test_every_colliding_name_gets_its_own_row(): void
    {
        Route::get('a/one', fn () => null)->name('alpha');
        Route::get('a/two', fn () => null)->name('alpha');
        Route::get('b/one', fn () => null)->name('beta');
        Route::get('b/two', fn () => null)->name('beta');

        $warnings = $this->of($this->audit()->run(), DuplicateRouteNameAudit::CHECK_DUPLICATE);

        $this->assertCount(2, $warnings);
        $details = implode("\n", array_map(fn (Finding $f) => $f->detail, $warnings));
        $this->assertStringContainsString('[alpha]', $details);
        $this->assertStringContainsString('[beta]', $details);
    }

    /**
     * Three or more claimants stay in one row for the name, and every one of them is listed. `route:cache`
     * cannot do this — it names one route and stops, so a host discovers its collisions one deploy attempt
     * at a time. Reporting all of them in a single pass is the whole reason this is worth having beside a
     * command that already throws.
     */
    public function test_three_claimants_are_all_listed_in_one_row(): void
    {
        Route::get('x/one', fn () => null)->name('triple');
        Route::get('x/two', fn () => null)->name('triple');
        Route::get('x/three', fn () => null)->name('triple');

        $warnings = $this->of($this->audit()->run(), DuplicateRouteNameAudit::CHECK_DUPLICATE);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('claimed by 3 routes', $warnings[0]->detail);
        foreach (['x/one', 'x/two', 'x/three'] as $uri) {
            $this->assertStringContainsString($uri, $warnings[0]->detail);
        }
    }

    // ── the trailing-dot split ──────────────────────────────────────────────────────────────────────

    /**
     * `~/Herd/prognosix-api`: ten routes carrying only the group's `n1.` name prefix. Laravel's
     * `addToSymfonyRoutesCollection()` treats a `.`-terminated duplicate as absent and generates a name,
     * which is why that host fails `route:cache` on `dicom.proxy` and never mentions `n1.` — so this is a
     * different defect with a different repair and must not be folded into the duplicate verdict.
     */
    public function test_a_group_prefix_only_name_is_a_separate_check(): void
    {
        Route::get('n1/status', fn () => null)->name('n1.');
        Route::get('n1/inferences', fn () => null)->name('n1.');

        $findings = $this->audit()->run();

        $this->assertSame([], $this->of($findings, DuplicateRouteNameAudit::CHECK_DUPLICATE));

        $prefix = $this->of($findings, DuplicateRouteNameAudit::CHECK_PREFIX_ONLY);
        $this->assertCount(1, $prefix);
        $this->assertStringContainsString('[n1.]', $prefix[0]->detail);
        $this->assertStringContainsString('n1/status', $prefix[0]->detail);
        $this->assertStringContainsString('n1/inferences', $prefix[0]->detail);
    }

    /**
     * And it says so: a reader coming off the duplicate finding will otherwise assume this one blocks
     * `route:cache` too, go looking for the exception, and not find it.
     */
    public function test_the_prefix_only_finding_states_that_route_cache_still_works(): void
    {
        Route::get('n1/status', fn () => null)->name('n1.');
        Route::get('n1/inferences', fn () => null)->name('n1.');

        $detail = $this->of($this->audit()->run(), DuplicateRouteNameAudit::CHECK_PREFIX_ONLY)[0]->detail;

        $this->assertStringContainsString('does NOT break `route:cache`', $detail);
        $this->assertStringContainsString('->name() on each route in the group', $detail);
    }

    // ── the negative control ────────────────────────────────────────────────────────────────────────

    /**
     * `~/Herd/splicewire-app`: 883 named routes, zero collisions, `route:cache` succeeds. Distinct names
     * are not findings, and an UNNAMED route cannot collide with anything — there is no name to share, so
     * excluding it is not an exemption.
     */
    public function test_distinct_names_and_unnamed_routes_are_not_findings(): void
    {
        Route::get('one', fn () => null)->name('one');
        Route::get('two', fn () => null)->name('two');
        Route::get('three', fn () => null);
        Route::get('four', fn () => null);

        $findings = $this->audit()->run();

        $this->assertSame([], $this->of($findings, DuplicateRouteNameAudit::CHECK_DUPLICATE));
        $this->assertSame([], $this->of($findings, DuplicateRouteNameAudit::CHECK_PREFIX_ONLY));

        $census = $this->of($findings, DuplicateRouteNameAudit::CHECK_CENSUS);
        $this->assertCount(1, $census);
        $this->assertSame(DoctorStatus::Pass, $census[0]->status);
        $this->assertStringContainsString('0 names claimed twice or more', $census[0]->detail);
    }

    /**
     * A legitimately-aliased pair — the same action mounted under two prefixes, one name each — is
     * deliberately NOT exempt. `~/Herd/prahsys-gateway` does this 80 times and genuinely cannot
     * `route:cache` (measured 2026-08-30, LogicException on `n1.status`), so an exemption would suppress a
     * live deploy blocker to make the report look tidy. Pinned here because it is the most tempting
     * exemption in the audit and someone will propose it.
     */
    public function test_a_deliberate_dual_prefix_alias_is_still_reported(): void
    {
        Route::get('api/n1/status', 'App\\Http\\Controllers\\StatusController@index')->name('n1.status');
        Route::get('payments/n1/status', 'App\\Http\\Controllers\\StatusController@index')->name('n1.status');

        $warnings = $this->of($this->audit()->run(), DuplicateRouteNameAudit::CHECK_DUPLICATE);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('api/n1/status', $warnings[0]->detail);
        $this->assertStringContainsString('payments/n1/status', $warnings[0]->detail);
        $this->assertStringContainsString('cannot `route:cache`', $warnings[0]->detail);
    }

    // ── the population gate ─────────────────────────────────────────────────────────────────────────

    /**
     * A table whose routes are all unnamed has no name for two routes to share. The audit says that in
     * words rather than emitting a clean bill it did not earn — the estate's recurring defect class is an
     * instrument that reports success by not running.
     */
    public function test_a_table_with_no_named_routes_reports_that_nothing_was_measured(): void
    {
        $router = new Router($this->app->make('events'), $this->app);
        $router->get('anonymous/one', fn () => null);
        $router->get('anonymous/two', fn () => null);

        $findings = (new DuplicateRouteNameAudit($router))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(DuplicateRouteNameAudit::CHECK_CENSUS, $findings[0]->check);
        $this->assertStringContainsString('Nothing was measured', $findings[0]->detail);
        $this->assertStringContainsString('2 assembled routes', $findings[0]->detail);
    }

    /** An empty table takes the same branch — there is no table state that produces a silent nothing. */
    public function test_an_empty_table_reports_that_nothing_was_measured(): void
    {
        $router = new Router($this->app->make('events'), $this->app);

        $findings = (new DuplicateRouteNameAudit($router))->run();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Nothing was measured', $findings[0]->detail);
    }

    // ── computed on read ────────────────────────────────────────────────────────────────────────────

    /**
     * Nothing is memoised. A host's table is still being assembled while providers boot, and a route
     * mounted after this audit is constructed has to be in the population — otherwise the audit records
     * boot order as truth, which is the shape that took `~/Herd/tower` off the air.
     */
    public function test_a_route_mounted_after_construction_is_in_the_population(): void
    {
        $router = new Router($this->app->make('events'), $this->app);
        $router->get('late/one', fn () => null)->name('late');

        $audit = new DuplicateRouteNameAudit($router);

        $this->assertSame([], $this->of($audit->run(), DuplicateRouteNameAudit::CHECK_DUPLICATE));

        $router->get('late/two', fn () => null)->name('late');

        $this->assertCount(1, $this->of($audit->run(), DuplicateRouteNameAudit::CHECK_DUPLICATE));
    }
}
