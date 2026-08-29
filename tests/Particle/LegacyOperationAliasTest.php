<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\ParticleSlotCollisionAudit;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\LegacyOperationAlias;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Routing\BeamRouteAction;
use Splicewire\Beam\Routing\RouteVisibility;
use Splicewire\Beam\Tests\TestCase;

/**
 * The `/op/` drop (particle-operation-surface 12) — the dual mount, and the three readers it needed.
 *
 * An operation now mounts at `{uri}/{id}/{op}`, with the `{uri}/{id}/op/{op}` spelling it shipped as
 * first kept alive as a deprecated alias. **A URL that shipped is a published contract**, and the
 * estate proves it: eight files across five roots hand-write `…/op/…` as a template literal and touch
 * no generated client at all, so deleting the segment would have produced live 404s that every test
 * suite, `tsc` run and doctor audit in the estate reports as green.
 *
 * The three assertions here are the three places the pair could go wrong, and each is a *silent*
 * failure rather than a loud one:
 *
 *  1. **The names have to split.** Two routes cannot share one — `RouteCollection::addLookups()`
 *     overwrites silently and Laravel only refuses the pair at `route:cache`.
 *  2. **The alias must be invisible to the manifest**, or the generated client carries both spellings
 *     and hands new code the one that is going away.
 *  3. **The alias must be invisible to {@see ParticleSlotCollisionAudit}**, or every operation in the
 *     estate reports as colliding with itself — the audit collapses `/op/` out of the URI and `.op.`
 *     out of the name, which maps the pair onto one key on BOTH axes at once.
 */
class LegacyOperationAliasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'sprockets',
            name: 'spin',
            kind: OperationKind::Task,
            model: 'App\\Models\\Sprocket',
            handle: fn () => null,
        ));

        Route::prefix('resources')->group(fn () => Particle::ops('sprockets', 'sprockets', 'spin'));
    }

    /** @return array<string, \Illuminate\Routing\Route> uri => route, for the two mounts of `spin` */
    protected function pair(): array
    {
        $found = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_ends_with($route->uri(), 'spin')) {
                $found[$route->uri()] = $route;
            }
        }

        return $found;
    }

    public function test_the_operation_mounts_twice_and_the_two_spellings_take_different_names(): void
    {
        $pair = $this->pair();

        $this->assertSame(
            ['resources/sprockets/{id}/spin', 'resources/sprockets/{id}/op/spin'],
            array_keys($pair),
            'The primary must be registered FIRST — a URI collision resolves by registration order, so the '
                .'supported spelling has to be the one that wins if one is ever manufactured.',
        );

        $this->assertSame('sprockets.spin', $pair['resources/sprockets/{id}/spin']->getName());
        $this->assertSame('sprockets.op.spin', $pair['resources/sprockets/{id}/op/spin']->getName());
    }

    public function test_only_the_legacy_alias_is_stamped_deprecated_and_carries_the_reporting_middleware(): void
    {
        $pair = $this->pair();
        $primary = $pair['resources/sprockets/{id}/spin'];
        $alias = $pair['resources/sprockets/{id}/op/spin'];

        $this->assertNull(BeamRouteAction::visibility($primary));
        $this->assertSame(RouteVisibility::Deprecated, BeamRouteAction::visibility($alias));

        $this->assertNotContains(LegacyOperationAlias::class, $primary->gatherMiddleware());
        $this->assertContains(LegacyOperationAlias::class, $alias->gatherMiddleware());
    }

    public function test_the_slot_collision_audit_does_not_report_the_alias_as_colliding_with_its_own_primary(): void
    {
        $findings = $this->app->make(ParticleSlotCollisionAudit::class)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(
            DoctorStatus::Pass,
            $findings[0]->status,
            'Without the Deprecated skip this is a permanent false positive at every host in the estate: '
                .'the audit collapses the pair onto one URI and one name. Finding: '.$findings[0]->detail,
        );
    }

    public function test_the_middleware_names_the_successor_url_and_collapses_only_the_first_op_segment(): void
    {
        // The path deliberately contains `/op/` TWICE. `str_replace` would rewrite both and point the
        // successor at a URL that does not exist; the collapse is leftmost-once, matching the audit's.
        $request = Request::create('https://host.test/resources/op/sprockets/1/op/spin', 'POST');

        $response = (new LegacyOperationAlias)->handle($request, fn () => new Response('ok'));

        $this->assertSame('true', $response->headers->get(LegacyOperationAlias::HEADER));
        $this->assertSame(
            '<https://host.test/resources/sprockets/1/op/spin>; rel="successor-version"',
            $response->headers->get('Link'),
        );
    }
}
