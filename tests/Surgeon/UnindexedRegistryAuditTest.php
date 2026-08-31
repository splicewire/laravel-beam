<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\Finding;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Surgeon\UnindexedRegistryAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * Registry-kernel ticket 73 step 1 — **declared on disk, absent from the index at this host**.
 *
 * The load-bearing test here is {@see test_it_reports_exactly_the_five_beam_rows_that_went_unindexed_for_three_days()},
 * which reconstructs the pre-`97489c7` state rather than asserting on a fixture. Without it this audit is
 * a claim: the defect it exists for is one that five green instruments walked past, so "it looks right"
 * is precisely the evidence that was already available and already wrong.
 *
 * Every scan below is pointed at **beam's own real `src/`**, not at a planted fixture. That is deliberate
 * and it is what makes the discriminator real — the same scan, the same 20-odd declared classes, and the
 * only thing that changes between the five-finding case and the zero-finding case is what the INDEX holds.
 * An audit that is not looking returns the same answer to both.
 */
class UnindexedRegistryAuditTest extends TestCase
{
    /** @var list<string> planted fixture paths, removed in teardown */
    private array $planted = [];

    private mixed $autoloader = null;

    protected function tearDown(): void
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
            $this->autoloader = null;
        }

        foreach ($this->planted as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }

        $this->planted = [];

        parent::tearDown();
    }

    /**
     * The five that `d3b2fd3` and `1a127aa` declared and conformed, that `97489c7` finally described, and
     * that nothing in the estate could see for the three days in between.
     *
     * @var list<string>
     */
    private const THE_FIVE = [
        'beam.capabilities',
        'beam.doctor.audits',
        'beam.install.steps',
        'beam.seed.steps',
        'schemas.sources',
    ];

    /** @return list<string> */
    private function beamSrc(): array
    {
        return [(string) realpath(__DIR__.'/../../src')];
    }

    /**
     * An index holding a stub at every root beam declares, minus `$absent`.
     *
     * The stubs are `BasicRegistry` instances built from each declaration, which is what `describe()`
     * reads — the audit asks only which ROOTS the index holds, so constructing the real registries would
     * add a boot's worth of side effects to prove nothing extra.
     *
     * @param  list<string>  $absent
     */
    private function indexDescribingAllBeamRootsExcept(array $absent): RegistryIndex
    {
        $index = new RegistryIndex;

        foreach ($this->auditOver($index)->declared() as $root => $class) {
            if (in_array($root, $absent, true)) {
                continue;
            }

            $declaration = (new \ReflectionClass($class))->getAttributes(IsRegistry::class)[0]->newInstance();

            $index->describe(new BasicRegistry($declaration));
        }

        return $index;
    }

    /** @param  list<string>|null  $roots */
    private function auditOver(RegistryIndex $index, ?array $roots = null): UnindexedRegistryAudit
    {
        return new UnindexedRegistryAudit($index, $roots ?? $this->beamSrc());
    }

    /** @param  list<Finding>  $findings */
    private function details(array $findings): string
    {
        return implode("\n", array_map(fn (Finding $f): string => $f->detail, $findings));
    }

    public function test_the_scan_reaches_beams_own_declarations_at_all(): void
    {
        // The control for every assertion below. A scan that reached nothing would make the five-row test
        // pass for the wrong reason — zero declared means zero unindexed means "exactly the five" fails,
        // but a future refactor could easily make it fail the other way round.
        $declared = $this->auditOver(new RegistryIndex)->declared();

        $this->assertGreaterThan(10, count($declared), 'the scan should reach beam\'s own declared registries');

        foreach (self::THE_FIVE as $root) {
            $this->assertArrayHasKey($root, $declared, "the scan should reach the declaration of `{$root}`");
        }
    }

    public function test_it_reports_exactly_the_five_beam_rows_that_went_unindexed_for_three_days(): void
    {
        // Pre-`97489c7`: every other beam registry described, these five declared and conforming and
        // absent from the index. This is the state `splicewire:beam:registry-conformance --check`,
        // `UndescribedRegistryAudit`, `surgeon:audit` and a 1793-test suite all read as clean.
        $audit = $this->auditOver($this->indexDescribingAllBeamRootsExcept(self::THE_FIVE));

        $this->assertSame(self::THE_FIVE, array_keys($audit->unindexed()));

        $findings = $audit->run();

        $this->assertCount(5, $findings);

        foreach ($findings as $finding) {
            $this->assertSame(UnindexedRegistryAudit::CHECK, $finding->check);
            $this->assertSame('Warn', $finding->status->name, 'advisory — index membership is a composition fact');
        }

        $detail = $this->details($findings);

        foreach (self::THE_FIVE as $root) {
            $this->assertStringContainsString("`{$root}`", $detail);
        }
    }

    public function test_the_same_scan_reports_nothing_once_those_five_are_described(): void
    {
        // The discriminator. Same roots, same scan, same 20-odd declarations — only the index differs.
        // An audit that is not looking answers both cases identically, so this is what makes the test
        // above evidence rather than a shape.
        $audit = $this->auditOver($this->indexDescribingAllBeamRootsExcept([]));

        $this->assertSame([], $audit->unindexed());

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame('Pass', $findings[0]->status->name);
        $this->assertTrue($findings[0]->conclusive);
    }

    public function test_a_described_but_empty_root_is_not_a_finding(): void
    {
        // `popcorn.invocables` is described at `~/Herd/splicewire-app` and holds ZERO entries, because
        // that host's invocables live on a subclass under root `composition`. Honest, not broken — and an
        // audit that read emptiness as absence would file it every run and be switched off within a week.
        $index = $this->indexDescribingAllBeamRootsExcept([]);

        foreach ($index->unfiltered()->keys() as $root) {
            if ((string) $root === '') {
                continue;
            }

            $this->assertSame([], $index->routeTo($root)?->unfiltered()->keys() ?? [], 'stub roots hold nothing');
        }

        $this->assertSame([], $this->auditOver($index)->unindexed());
    }

    public function test_it_reports_its_own_blindness_when_there_is_nothing_to_scan(): void
    {
        // Every failure mode of a filesystem scan produces an empty list, and an empty list of findings is
        // what a healthy estate produces. A `Pass` here would be the estate's signature defect verbatim.
        $audit = $this->auditOver($this->indexDescribingAllBeamRootsExcept([]), roots: []);

        $this->assertFalse($audit->detectionAvailable());

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive, 'no scan roots is "I could not look", not "all clear"');
        $this->assertStringContainsString('could not look', $findings[0]->detail);
        $this->assertStringContainsString('is not a statement that every declared registry is indexed', $findings[0]->detail);
    }

    public function test_an_index_that_never_filled_is_reported_rather_than_producing_a_wall_of_findings(): void
    {
        // A bare index holds only its own zero-segment root. Reporting every declared class as unindexed
        // would be a statement about the harness, not the estate — and it is the exact shape a package
        // testbench produces, where `PopcornServiceProvider` may never have run.
        $findings = $this->auditOver(new RegistryIndex)->run();

        $this->assertCount(1, $findings);
        $this->assertSame('Warn', $findings[0]->status->name);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('holds nothing but its own zero-segment root', $findings[0]->detail);
    }

    public function test_the_pass_states_what_it_does_not_cover(): void
    {
        // A green gate that reads as a clean estate is how a check stops being read. The pass names the
        // population it excluded and whose check owns it.
        $findings = $this->auditOver($this->indexDescribingAllBeamRootsExcept([]))->run();

        $this->assertStringContainsString('NOT covered', $findings[0]->detail);
        $this->assertStringContainsString('do not implement `Registry`', $findings[0]->detail);
        $this->assertStringContainsString('declared in a path no scan root names', $findings[0]->detail);
        $this->assertStringContainsString('holding zero entries is not', $findings[0]->detail);
    }

    /**
     * Plant a fixture file under a fresh temp root and make it autoloadable, returning the root.
     *
     * The autoloader registration is the load-bearing half. `AttributedClassScanner` reaches a class
     * through `class_exists()`, which asks the AUTOLOADER — a file sitting in a directory the autoloader
     * knows nothing about is invisible to it, so a fixture written and not registered would make every
     * assertion below vacuously pass. `require`ing the file instead is not an option for the unloadable
     * case: that fatals at declaration time, which is the very condition under test.
     */
    private function plant(string $file, string $body): string
    {
        $root = sys_get_temp_dir().'/beam-unindexed-'.getmypid().'-'.mt_rand();
        @mkdir($root, 0777, true);
        file_put_contents($root.'/'.$file, $body);

        $this->planted[] = $root.'/'.$file;
        $this->planted[] = $root;

        spl_autoload_register($this->autoloader = static function (string $class) use ($root): void {
            if (! str_starts_with($class, 'Beam\\Tests\\UnindexedFixtures\\')) {
                return;
            }

            $path = $root.'/'.str_replace('Beam\\Tests\\UnindexedFixtures\\', '', $class).'.php';

            if (is_file($path)) {
                require $path;
            }
        });

        return $root;
    }

    public function test_a_declared_class_that_cannot_conform_is_excluded_rather_than_reported(): void
    {
        // `describe()` takes a conforming Registry, so a declared class that implements nothing CANNOT be
        // described and a finding here would be unactionable. `RegistryConformanceAudit`'s `contract`
        // check owns it, and gates on it. The live estate row is `InMemoryOverlayRegistry`.
        $root = $this->plant('NonConforming.php', <<<'PHP'
<?php
namespace Beam\Tests\UnindexedFixtures;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryArity;
#[IsRegistry(root: 'fixture.non-conforming', of: 'nothing', arity: RegistryArity::PickOne)]
class NonConforming {}
PHP);

        $audit = new UnindexedRegistryAudit(new RegistryIndex, [$root]);

        $this->assertSame([], $audit->unindexed(), 'a non-conforming declaration is not this audit\'s finding');
        $this->assertSame(['fixture.non-conforming'], array_keys($audit->nonConforming()));
    }

    public function test_it_warns_about_classes_the_scan_could_not_load_instead_of_dropping_them_silently(): void
    {
        // `class_exists()` AUTOLOADS, and a class whose parent is absent raises rather than returning
        // false. Before php-popcorn's fix the scanner DIED on one such file at `~/Herd/splicewire-app`
        // (`laravel-prism-plus`'s `src/Testing/RerankProviderConformanceTest`). Swallowing it silently
        // would be the other half of the same defect: a short list that reads like a complete one.
        //
        // The fixture NAMES `IsRegistry`, because `candidateFiles()` now filters on exactly that before
        // autoloading anything — so this reproduces the case that survives the filter: a file that really
        // is a registry declaration and really cannot be loaded here.
        $root = $this->plant('Orphan.php', <<<'PHP'
<?php
namespace Beam\Tests\UnindexedFixtures;
use Rushing\Popcorn\Registries\IsRegistry;
class Orphan extends \Beam\Tests\No\Such\Parental {}
PHP);

        $audit = new UnindexedRegistryAudit($this->indexDescribingAllBeamRootsExcept([]), [$root]);

        $findings = $audit->run();

        $this->assertNotEmpty($findings);
        $this->assertStringContainsString('REACH:', $this->details($findings));
        $this->assertStringContainsString('Orphan', $this->details($findings));
    }

    public function test_it_is_registered_on_the_doctor_manifest_and_is_not_a_gate(): void
    {
        // Advisory is not a property of the class, it is a property of the REGISTRATION — asserting on
        // the finding's status would leave the one line that actually decides it untested.
        $registrations = array_values(array_filter(
            $this->app->make(\Splicewire\Beam\Doctor\BeamDoctorManifest::class)->registrations(),
            fn ($r): bool => $r->audit === UnindexedRegistryAudit::class,
        ));

        $this->assertCount(1, $registrations);
        $this->assertFalse($registrations[0]->gate, 'index membership is a composition fact and must not gate');
    }
}
