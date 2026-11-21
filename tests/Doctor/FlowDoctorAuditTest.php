<?php

use Illuminate\Contracts\Foundation\Application;
use Rushing\Doctor\DoctorStatus;
use Rushing\Popcorn\Laravel\PopcornManager;
use Splicewire\Beam\Conformance\BeamManifest;
use Splicewire\Beam\Doctor\FlowDoctorAudit;
use Splicewire\Beam\Flows\FlowCatalog;
use Splicewire\Beam\Flows\FlowDeclaration;
use Splicewire\Beam\Flows\FlowGate;
use Splicewire\Beam\Flows\FlowState;
use Splicewire\Beam\Flows\Gates\AlwaysOn;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

// ── fixture gates ────────────────────────────────────────────────────────────────────────────────

class FlowDoctorAlwaysOff implements FlowGate
{
    public function state(): FlowState
    {
        return FlowState::Off;
    }
}

class FlowDoctorReportsAbsent implements FlowGate
{
    public function state(): FlowState
    {
        return FlowState::Absent;
    }
}

class FlowDoctorThrowingGate implements FlowGate
{
    public function state(): FlowState
    {
        throw new RuntimeException('gate boom');
    }
}

// ── the probe: overrides the host-read seams so classification is tested without a filesystem,
//    a route table, or a real composer install. ──────────────────────────────────────────────────

class ProbeFlowDoctorAudit extends FlowDoctorAudit
{
    /**
     * @param  list<string>  $registeredRoutes
     * @param  list<string>  $installedPackages
     */
    public function __construct(
        Application $app,
        FlowCatalog $catalog,
        PopcornManager $popcorn,
        private BeamManifest $manifestDouble,
        private array $registeredRoutes = [],
        private array $installedPackages = [],
    ) {
        parent::__construct($app, $catalog, $popcorn);
    }

    protected function manifest(): BeamManifest
    {
        return $this->manifestDouble;
    }

    protected function missingRoutes(FlowDeclaration $declaration): array
    {
        return array_values(array_filter(
            $declaration->routes,
            fn (string $name): bool => ! in_array($name, $this->registeredRoutes, true),
        ));
    }

    protected function packageInstalled(string $package): bool
    {
        return in_array($package, $this->installedPackages, true);
    }
}

// ── helpers ──────────────────────────────────────────────────────────────────────────────────────

/**
 * @param  array<string, mixed>  $manifestData
 * @param  list<string>  $routes
 * @param  list<string>  $installed
 */
function flowDoctorProbe(FlowCatalog $catalog, array $manifestData = [], array $routes = [], array $installed = []): ProbeFlowDoctorAudit
{
    return new ProbeFlowDoctorAudit(
        app(),
        $catalog,
        app(PopcornManager::class),
        new BeamManifest($manifestData),
        $routes,
        $installed,
    );
}

/** The one finding whose detail carries `[$tag]`, or null. */
function flowDoctorFinding(array $findings, string $tag): ?object
{
    foreach ($findings as $finding) {
        if (str_contains($finding->detail, "[{$tag}]")) {
            return $finding;
        }
    }

    return null;
}

// ── (1) flow classification, in PRD order ──────────────────────────────────────────────────────────

it('notes a flow whose parent surface is not declared as structurally absent, never an error', function () {
    // `account.billing` declared (commerce) but `account` (the accounts surface) is not → structural
    // absence: a note, conclusive=false, not a failure. Mutation control below.
    $catalog = new FlowCatalog;
    $catalog->register('account.billing', new FlowDeclaration('account.billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/commerce');

    $finding = flowDoctorFinding(flowDoctorProbe($catalog)->run(), FlowDoctorAudit::TAG_ABSENT);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeFalse()
        ->and($finding->detail)->toContain('account.billing');
});

it('stops calling a flow structurally absent once its parent surface IS declared (discriminating)', function () {
    // The mutation that flips the note off: declare the parent. Now it classifies by gate instead.
    $catalog = new FlowCatalog;
    $catalog->register('account', new FlowDeclaration('account', 'Account', [], AlwaysOn::class, null, []), 'pkg/accounts');
    $catalog->register('account.billing', new FlowDeclaration('account.billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/commerce');

    expect(flowDoctorFinding(flowDoctorProbe($catalog)->run(), FlowDoctorAudit::TAG_ABSENT))->toBeNull();
});

it('notes a flow whose gate reports Absent as structurally absent', function () {
    $catalog = new FlowCatalog;
    $catalog->register('auth.passkeys', new FlowDeclaration('auth.passkeys', 'Passkeys', [], FlowDoctorReportsAbsent::class, null, []), 'pkg/auth');
    $catalog->register('auth', new FlowDeclaration('auth', 'Auth', [], AlwaysOn::class, null, []), 'pkg/auth');

    $finding = flowDoctorFinding(flowDoctorProbe($catalog)->run(), FlowDoctorAudit::TAG_ABSENT);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeFalse();
});

it('reports a gate that throws as a finding and never lets it propagate', function () {
    $catalog = new FlowCatalog;
    $catalog->register('billing', new FlowDeclaration('billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/commerce');
    $catalog->register('billing.subscribe', new FlowDeclaration('billing.subscribe', 'Subscribe', [], FlowDoctorThrowingGate::class, null, []), 'pkg/commerce');

    // run() must not throw — the gate's RuntimeException is caught and reported.
    $findings = flowDoctorProbe($catalog)->run();
    $finding = flowDoctorFinding($findings, FlowDoctorAudit::TAG_GATE_THREW);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Warn)
        ->and($finding->detail)->toContain('billing.subscribe');
});

it('passes a flow that is off WITH a matching BEAM.md Deviation (deviated)', function () {
    $catalog = new FlowCatalog;
    $catalog->register('teams', new FlowDeclaration('teams', 'Teams', [], AlwaysOn::class, null, []), 'pkg/accounts');
    $catalog->register('teams.team-of-one', new FlowDeclaration('teams.team-of-one', 'Solo', [], FlowDoctorAlwaysOff::class, null, []), 'pkg/accounts');

    $deviation = ['deviations' => [['branch' => 'teams', 'status' => 'partial', 'flows' => ['team-of-one']]]];
    $finding = flowDoctorFinding(flowDoctorProbe($catalog, $deviation)->run(), FlowDoctorAudit::TAG_DEVIATED);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->detail)->toContain('teams.team-of-one');
});

it('warns a flow that is off with NO matching Deviation (delta — discriminating against deviated)', function () {
    // The mutation: the same off flow, minus the Deviation, is a Delta rather than deviated.
    $catalog = new FlowCatalog;
    $catalog->register('teams', new FlowDeclaration('teams', 'Teams', [], AlwaysOn::class, null, []), 'pkg/accounts');
    $catalog->register('teams.team-of-one', new FlowDeclaration('teams.team-of-one', 'Solo', [], FlowDoctorAlwaysOff::class, null, []), 'pkg/accounts');

    $findings = flowDoctorProbe($catalog)->run();

    expect(flowDoctorFinding($findings, FlowDoctorAudit::TAG_DEVIATED))->toBeNull()
        ->and(flowDoctorFinding($findings, FlowDoctorAudit::TAG_DELTA))->not->toBeNull()
        ->and(flowDoctorFinding($findings, FlowDoctorAudit::TAG_DELTA)->status)->toBe(DoctorStatus::Warn);
});

it('warns a flow that is on while a Deviation says it is off (stale)', function () {
    $catalog = new FlowCatalog;
    $catalog->register('teams', new FlowDeclaration('teams', 'Teams', [], AlwaysOn::class, null, []), 'pkg/accounts');
    $catalog->register('teams.team-of-one', new FlowDeclaration('teams.team-of-one', 'Solo', [], AlwaysOn::class, null, []), 'pkg/accounts');

    $deviation = ['deviations' => [['branch' => 'teams', 'status' => 'partial', 'flows' => ['team-of-one']]]];
    $finding = flowDoctorFinding(flowDoctorProbe($catalog, $deviation)->run(), FlowDoctorAudit::TAG_STALE);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Warn);
});

it('fails a flow that is on and not deviated but declares an unregistered entry route', function () {
    $catalog = new FlowCatalog;
    $catalog->register('billing', new FlowDeclaration('billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/commerce');
    $catalog->register('billing.subscribe', new FlowDeclaration('billing.subscribe', 'Subscribe', ['billing.subscribe.show'], AlwaysOn::class, null, []), 'pkg/commerce');

    // No routes registered → the declared route name is missing → FAIL.
    $finding = flowDoctorFinding(flowDoctorProbe($catalog, routes: [])->run(), FlowDoctorAudit::TAG_ROUTE);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Fail)
        ->and($finding->detail)->toContain('billing.subscribe.show');
});

it('does NOT fail a missing-route flow when it is off and a matching Deviation covers it (closed registration)', function () {
    // The done-when: a closed registration (gate off) with a matching Deviation reaches step 2 and never
    // the route check, so a route it no longer serves is not an error.
    $catalog = new FlowCatalog;
    $catalog->register('billing', new FlowDeclaration('billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/commerce');
    $catalog->register('billing.gifting', new FlowDeclaration('billing.gifting', 'Gifting', ['billing.gifting.show'], FlowDoctorAlwaysOff::class, null, []), 'pkg/commerce');

    $deviation = ['deviations' => [['branch' => 'billing', 'status' => 'partial', 'flows' => ['gifting']]]];
    $findings = flowDoctorProbe($catalog, $deviation, routes: [])->run();

    expect(flowDoctorFinding($findings, FlowDoctorAudit::TAG_ROUTE))->toBeNull()
        ->and(flowDoctorFinding($findings, FlowDoctorAudit::TAG_DEVIATED))->not->toBeNull();
});

it('reports a duplicate key naming both registrants', function () {
    $catalog = new FlowCatalog;
    // Two packages both declare `billing`; each also declares a distinct sub-flow so both are in the
    // registrant universe the audit walks keysBy() over.
    $catalog->register('billing', new FlowDeclaration('billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/a');
    $catalog->register('billing.subscribe', new FlowDeclaration('billing.subscribe', 'Subscribe', [], AlwaysOn::class, null, []), 'pkg/a');
    $catalog->register('billing', new FlowDeclaration('billing', 'Billing too', [], AlwaysOn::class, null, []), 'pkg/b');
    $catalog->register('billing.gifting', new FlowDeclaration('billing.gifting', 'Gifting', [], AlwaysOn::class, null, []), 'pkg/b');

    $finding = flowDoctorFinding(flowDoctorProbe($catalog)->run(), FlowDoctorAudit::TAG_DUPLICATE);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Warn)
        ->and($finding->detail)->toContain('pkg/a')
        ->and($finding->detail)->toContain('pkg/b');
});

// ── (2) BEAM.md key against the catalog (02 R3-Q3's four outcomes) ──────────────────────────────────

it('passes a BEAM.md Deviation key that is declared, naming the registrant', function () {
    $catalog = new FlowCatalog;
    $catalog->register('teams', new FlowDeclaration('teams', 'Teams', [], AlwaysOn::class, null, []), 'pkg/accounts');
    $catalog->register('teams.team-of-one', new FlowDeclaration('teams.team-of-one', 'Solo', [], FlowDoctorAlwaysOff::class, null, []), 'splicewire/laravel-beam-accounts');

    $deviation = ['deviations' => [['branch' => 'teams', 'status' => 'partial', 'flows' => ['team-of-one']]]];
    $finding = flowDoctorFinding(flowDoctorProbe($catalog, $deviation)->run(), FlowDoctorAudit::TAG_DECLARED);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->detail)->toContain('splicewire/laravel-beam-accounts');
});

it('NOTES an undeclared BEAM.md key whose package is NOT installed (removed package)', function () {
    // The catalog has unrelated flows; the Deviation names a key no flow declares, attributed to a
    // package that is not installed → structural absence, a note, conclusive=false.
    $catalog = new FlowCatalog;
    $catalog->register('billing', new FlowDeclaration('billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/commerce');

    $deviation = ['deviations' => [['branch' => 'gallery', 'package' => 'splicewire/laravel-beam-gallery']]];
    $finding = flowDoctorFinding(flowDoctorProbe($catalog, $deviation, installed: [])->run(), FlowDoctorAudit::TAG_PACKAGE_ABSENT);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Pass)
        ->and($finding->conclusive)->toBeFalse();
});

it('FAILS an undeclared BEAM.md key whose package IS installed (typo / dropped flow) — discriminating against removed', function () {
    // The same shape as above, but the attributed package is installed → error, not a note. This is the
    // typo-vs-removed pair: the only difference is whether `package:` resolves to an installed package.
    $catalog = new FlowCatalog;
    $catalog->register('billing', new FlowDeclaration('billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/commerce');

    $deviation = ['deviations' => [['branch' => 'gallery', 'package' => 'splicewire/laravel-beam-gallery']]];
    $finding = flowDoctorFinding(
        flowDoctorProbe($catalog, $deviation, installed: ['splicewire/laravel-beam-gallery'])->run(),
        FlowDoctorAudit::TAG_PACKAGE_INSTALLED,
    );

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Fail);
});

it('FAILS an undeclared, unattributed BEAM.md key', function () {
    $catalog = new FlowCatalog;
    $catalog->register('billing', new FlowDeclaration('billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/commerce');

    $deviation = ['deviations' => [['branch' => 'nonsense']]];
    $finding = flowDoctorFinding(flowDoctorProbe($catalog, $deviation)->run(), FlowDoctorAudit::TAG_UNATTRIBUTED);

    expect($finding)->not->toBeNull()
        ->and($finding->status)->toBe(DoctorStatus::Fail)
        ->and($finding->detail)->toContain('nonsense');
});

// ── host-dependent answers never throw ─────────────────────────────────────────────────────────────

it('reports an inconclusive pass, not a throw, on a host that declares no flows', function () {
    $findings = flowDoctorProbe(new FlowCatalog)->run();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->status)->toBe(DoctorStatus::Pass)
        ->and($findings[0]->conclusive)->toBeFalse()
        ->and($findings[0]->detail)->toContain('empty');
});

it('classifies without throwing when there is no manifest and no routes registered', function () {
    $catalog = new FlowCatalog;
    $catalog->register('billing', new FlowDeclaration('billing', 'Billing', [], AlwaysOn::class, null, []), 'pkg/commerce');
    $catalog->register('billing.subscribe', new FlowDeclaration('billing.subscribe', 'Subscribe', [], AlwaysOn::class, null, []), 'pkg/commerce');

    // empty manifest (no deviations), empty routes → a clean, on, routeless flow, no throw, a summary.
    $findings = flowDoctorProbe($catalog)->run();

    expect($findings)->not->toBeEmpty()
        ->and(collect($findings)->last()->detail)->toContain('Classified');
});
