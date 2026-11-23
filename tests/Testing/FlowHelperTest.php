<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\SkippedWithMessageException;
use Rushing\Popcorn\Registries\Registrant;
use Splicewire\Beam\Conformance\BeamManifest;
use Splicewire\Beam\Flows\DeclaresFlows;
use Splicewire\Beam\Flows\FlowCatalog;
use Splicewire\Beam\Flows\FlowDeclaration;
use Splicewire\Beam\Flows\Gates\AlwaysOn;
use Splicewire\Beam\Flows\ListsFlows;
use Splicewire\Beam\Testing\Flow;
use Splicewire\Beam\Testing\FlowOutcome;
use Splicewire\Beam\Testing\FlowRun;
use Splicewire\Beam\Testing\FlowScreenshot;
use Splicewire\Beam\Tests\TestCase;

use function Splicewire\Beam\Testing\flow;

uses(TestCase::class, RefreshDatabase::class);

class HelperFixtureFlows implements DeclaresFlows
{
    use ListsFlows;

    public static function flows(): array
    {
        return [
            new FlowDeclaration('account.security', 'Security', ['security.edit'], AlwaysOn::class, null, ['owner', 'solo']),
            new FlowDeclaration('auth.register', 'Registration', ['register'], AlwaysOn::class, null, []),
        ];
    }
}

/** Declare the fixture flows + bind a BEAM.md manifest fixture, so flow() resolves against them. */
function helperSetup(string $manifestBody = ''): void
{
    $catalog = app(FlowCatalog::class);
    Registrant::scoped('fixture/pkg', fn () => $catalog->declare(HelperFixtureFlows::class));
    app()->instance(BeamManifest::class, BeamManifest::parse("---\n{$manifestBody}\n---\n# h\n"));
    @unlink(FlowRun::resultsPath());
}

afterEach(fn () => @unlink(FlowRun::resultsPath()));

// ── pure logic (also covered by the standalone smoke; here through the suite) ────────────────────

it('names a screenshot by the flow key, keeping dots, with role and width', function () {
    expect(Flow::screenshotName('account.security.passkeys', 'owner', 1280))->toBe('account.security.passkeys--owner@1280')
        // the collision pair is two distinct filenames at a given width
        ->and(Flow::screenshotName('account.security.passkeys', null, 1280))
        ->not->toBe(Flow::screenshotName('account.security-passkeys', null, 1280));
});

it('suffixes the capture filename with .png even when the flow key carries a dot', function (string $raw, string $expected) {
    // pest-plugin-browser's screenshot(filename:) only auto-appends .png when the filename has no
    // extension; a dotted flow key (e.g. readings.offering@1280) reads as already-extensioned, so the
    // capture lands WITHOUT .png and record-baselines (which globs *.png) promotes nothing on every
    // host. FlowScreenshot::name() must carry the .png so a dotted key resolves to a real .png file.
    expect((new FlowScreenshot($raw))->name())->toBe($expected);
})->with([
    ['readings.offering@1280', 'readings.offering@1280.png'],
    ['subscribe.door@1280', 'subscribe.door@1280.png'],
    ['account.security.passkeys--owner@1280', 'account.security.passkeys--owner@1280.png'],
    // idempotent: an already-suffixed name is not doubled
    ['auth.login@1280.png', 'auth.login@1280.png'],
]);

it('refuses a key with a double dash, a dot-folded segment or upper-case', function (string $key) {
    Flow::assertValidKey($key);
})->throws(InvalidArgumentException::class)->with(['account.sec--x', 'Account.security', 'account.2fa']);

// ── the helper on a non-deviated flow: enters, records, names ────────────────────────────────────

it('enters a non-deviated flow, writes an entered FlowResultData row, and returns the screenshot name', function () {
    helperSetup(); // no deviations

    $shot = flow('account.security', role: 'owner');

    expect($shot->name())->toBe('account.security--owner@1280.png');

    $rows = array_map(
        fn (string $l) => json_decode($l, true),
        array_filter(explode("\n", (string) @file_get_contents(FlowRun::resultsPath())))
    );
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['flow'])->toBe('account.security')
        ->and($rows[0]['role'])->toBe('owner')
        ->and($rows[0]['package'])->toBe('fixture/pkg')
        ->and($rows[0]['outcome'])->toBe(FlowOutcome::Entered->value)
        ->and($rows[0]['reason'])->toBeNull()
        // The `test` field is the Sweep's join key (ticket 02 U1): it must equal the JUnit <testcase name>,
        // which PHPUnit's JUnit logger derives from the test's methodName — for Pest the `__pest_evaluable_<desc>`
        // form (JunitXmlLogger::name()). FlowRun records $test->nameWithDataSet(), which yields that exact form,
        // so the row joins to JUnit. (Measured against real JUnit output by 10's gate/numero proof.)
        ->and($rows[0]['test'])->toContain('__pest_evaluable_it_enters_a_non_deviated_flow');
});

// ── the helper on a deviated flow: skips with the Deviation reason, records skipped-deviation ─────

it('skips a deviated flow with the covering Deviation reason and records it', function () {
    helperSetup(<<<'YAML'
deviations:
  - branch: account
    status: partial
    flows: [security]
    note: no end-user security surface in this brand
YAML);

    // flow() calls markTestSkipped, which throws SkippedWithMessageException — catch it so this wrapper
    // test asserts the behaviour rather than itself being skipped.
    $skip = null;
    try {
        flow('account.security', role: 'owner');
    } catch (SkippedWithMessageException $e) {
        $skip = $e->getMessage();
    }

    expect($skip)->toBe('no end-user security surface in this brand');

    $rows = array_map(
        fn (string $l) => json_decode($l, true),
        array_filter(explode("\n", (string) @file_get_contents(FlowRun::resultsPath())))
    );
    expect($rows[0]['outcome'])->toBe(FlowOutcome::SkippedDeviation->value)
        ->and($rows[0]['reason'])->toBe('no end-user security surface in this brand');
});

// ── validations: role, undeclared flow, reset trait ──────────────────────────────────────────────

it('refuses a role the declaration does not list', function () {
    helperSetup();
    flow('account.security', role: 'admin'); // declared roles are owner, solo
})->throws(InvalidArgumentException::class);

it('refuses a flow no package declares', function () {
    helperSetup();
    flow('nope.not-declared');
})->throws(InvalidArgumentException::class);

it('requires the host test case to use one of Laravel four reset traits', function () {
    // a class with no reset trait is refused; one with RefreshDatabase passes.
    expect(fn () => FlowRun::assertResetTrait(stdClass::class))->toThrow(InvalidArgumentException::class);

    // PHPUnit\TestCase::__construct requires a name; pass a dummy — we only need the class-string for the trait check.
    $withTrait = new class('flow-helper-reset-trait-probe') extends PHPUnit\Framework\TestCase
    {
        use RefreshDatabase;
    };
    FlowRun::assertResetTrait($withTrait::class);
    expect(true)->toBeTrue();
});
