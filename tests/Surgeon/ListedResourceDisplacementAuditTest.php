<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\ListedResourceDisplacementAudit;
use Splicewire\Beam\Tests\Fixtures\Listing\HostPaperResource;
use Splicewire\Beam\Tests\Fixtures\Listing\SoleListedResource;
use Splicewire\Beam\Tests\Fixtures\Registrar\ScannedPaperModel;
use Splicewire\Beam\Tests\Fixtures\Registrar\ScannedPaperResource;
use Splicewire\Beam\Tests\TestCase;

/**
 * Registry-kernel ticket 67. The audit runs against a REAL provider graph rather than a hand-built
 * registry, because the defect it reports is produced by Laravel's boot order — `BeamServiceProvider`
 * reads the explicit list inside its own `boot()`, and everything else registers afterwards.
 *
 * ⚠️ **The negative control is the point of this file.** The estate's live population of the actual
 * defect is currently ZERO (measured 2026-08-28 across all seven roots with a non-empty list), and it
 * reached zero by a config edit at one host that had nothing to do with the ordering. An audit whose
 * only evidence is "it stays green on the estate" is indistinguishable from an audit that cannot see
 * anything, so `test_a_listed_class_the_scan_displaces_is_reported` deliberately manufactures the
 * displacement on the exact intake and asserts the audit names it.
 */
class ListedResourceDisplacementAuditTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LaterConsumerProvider::class];
    }

    // ── environments ────────────────────────────────────────────────────────────────────────────────

    /** The defect: the host lists its own class, and beam's OWN scan registers over it. */
    protected function listAClassTheScanDisplaces(Application $app): void
    {
        $app['config']->set('beam.core.resources.classes', [HostPaperResource::class]);
        $app['config']->set('beam.core.resources.discover_paths', [__DIR__.'/../Fixtures/Registrar']);
    }

    /** Redundant: the listed class and the scanned class are the same class. */
    protected function listTheSameClassTheScanFinds(Application $app): void
    {
        $app['config']->set('beam.core.resources.classes', [ScannedPaperResource::class]);
        $app['config']->set('beam.core.resources.discover_paths', [__DIR__.'/../Fixtures/Registrar']);
    }

    /** Sole: nothing else ever registers that key. */
    protected function listAKeyNothingElseTouches(Application $app): void
    {
        $app['config']->set('beam.core.resources.classes', [SoleListedResource::class]);
        $app['config']->set('beam.core.resources.discover_paths', []);
    }

    /** Rescued: the listing loses to the scan, and a later provider re-registers the SAME class. */
    protected function listAClassALaterProviderRestores(Application $app): void
    {
        $app['config']->set('beam.core.resources.classes', [HostPaperResource::class]);
        $app['config']->set('beam.core.resources.discover_paths', [__DIR__.'/../Fixtures/Registrar']);
        $app['config']->set('beam.testing.restore_host_paper', true);
    }

    protected function listNothing(Application $app): void
    {
        $app['config']->set('beam.core.resources.classes', []);
        $app['config']->set('beam.core.resources.discover_paths', []);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    /** @return list<Finding> */
    private function findings(): array
    {
        return $this->app->make(ListedResourceDisplacementAudit::class)->run();
    }

    /** @param  list<Finding>  $findings */
    private function checks(array $findings): array
    {
        return array_map(fn (Finding $f) => $f->check, $findings);
    }

    /** @param  list<Finding>  $findings */
    private function detailFor(array $findings, string $check): string
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding->detail;
            }
        }

        $this->fail("No [{$check}] finding was raised; got: ".implode(', ', $this->checks($findings)));
    }

    // ── the negative control ────────────────────────────────────────────────────────────────────────

    /**
     * The defect itself, manufactured. The host lists `HostPaperResource` (perPage 42) for
     * `scanned-papers`; beam's scan registers `ScannedPaperResource` (perPage 7) afterwards and takes
     * the key. Without this test the audit's estate-wide green means nothing.
     */
    #[DefineEnvironment('listAClassTheScanDisplaces')]
    public function test_a_listed_class_the_scan_displaces_is_reported(): void
    {
        // The precondition is itself an assertion: if the ordering ever changes, this test must fail
        // LOUDLY rather than quietly stop exercising the branch it exists for.
        $this->assertSame(7, $this->app->make(ParticleResourceRegistry::class)->get('scanned-papers')->perPage);

        $findings = $this->findings();

        $detail = $this->detailFor($findings, 'resource.listing.displaced');
        $this->assertStringContainsString('scanned-papers', $detail);
        $this->assertStringContainsString(HostPaperResource::class, $detail);
        $this->assertStringContainsString(ScannedPaperResource::class, $detail);

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
    }

    // ── the three verdicts that must NOT be reported as the defect ──────────────────────────────────

    /**
     * A listing displaced by its OWN class. 16 of the 31 entries measured across the estate are this,
     * so reporting it as a defect would make the audit 75% noise on the only hosts that can trip it.
     */
    #[DefineEnvironment('listTheSameClassTheScanFinds')]
    public function test_a_listing_displaced_by_its_own_class_is_counted_not_warned(): void
    {
        $findings = $this->findings();

        $this->assertSame(['resource.listing'], $this->checks($findings));
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('1 re-registered by the same class', $findings[0]->detail);
    }

    /**
     * The live counter-example the audit must never break: the listing is the ONLY registration, so a
     * "you listed something already attributed" check would delete the resource.
     */
    #[DefineEnvironment('listAKeyNothingElseTouches')]
    public function test_a_sole_listing_is_silent(): void
    {
        $findings = $this->findings();

        $this->assertSame(['resource.listing'], $this->checks($findings));
        $this->assertStringContainsString('1 sole registration', $findings[0]->detail);
    }

    /**
     * The flagship's live shape on `tokens` and `invitations`: the listing loses, and a package that
     * boots later puts the same class back. Correct today, and correct only because of the boot order
     * of two packages neither of which knows about the other — so it is reported, not passed.
     */
    #[DefineEnvironment('listAClassALaterProviderRestores')]
    public function test_a_listing_rescued_by_a_later_registration_is_reported(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);

        $this->assertSame(42, $registry->get('scanned-papers')->perPage, 'the later provider should hold the key');

        $detail = $this->detailFor($this->findings(), 'resource.listing.rescued');
        $this->assertStringContainsString('scanned-papers', $detail);
        $this->assertStringContainsString(HostPaperResource::class, $detail);
    }

    /**
     * An empty population is NAMED, not folded into a generic clean — `Rushing\Doctor\DoctorAudit`'s
     * standing obligation, because Pass/Warn/Fail cannot carry "inconclusive".
     */
    #[DefineEnvironment('listNothing')]
    public function test_an_empty_listing_says_it_measured_nothing(): void
    {
        $findings = $this->findings();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('Nothing was measured', $findings[0]->detail);
    }
}

/**
 * A package provider booting after beam. In the `rescued` environment it re-registers the host's own
 * listed class — which is what `splicewire/tower`'s `TowerFrameResourceProvider` does for `tokens` at
 * the flagship, unaware that `laravel-beam-accounts` displaced the host's listing a moment earlier.
 */
class LaterConsumerProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('beam.testing.restore_host_paper', false)) {
            return;
        }

        $this->app->make(ParticleResourceRegistry::class)->register(
            new ParticleResource(
                key: 'scanned-papers',
                backing: ScannedPaperModel::class,
                data: HostPaperResource::class,
                perPage: 42,
            ),
            by: self::class,
        );
    }
}
