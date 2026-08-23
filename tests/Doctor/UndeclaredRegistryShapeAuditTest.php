<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Doctor\RegistryConformanceAudit;
use Splicewire\Beam\Doctor\UndeclaredRegistryShapeAudit;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;
use Splicewire\Beam\Tests\Doctor\Fixtures\ConformingFixtureRegistry;
use Splicewire\Beam\Tests\Surgeon\UndescribedRegistryAuditTest;
use Splicewire\Beam\Tests\TestCase;

/**
 * registry-kernel ticket 35 §2 — the advisory report, its three dispositions and its three staleness
 * findings.
 *
 * Every case plants a real registry-shaped class and a real provider binding it into a temp root the scan
 * can see, for the reason {@see UndescribedRegistryAuditTest} spells out: the
 * structural test excludes `tests/` and `fixtures/` paths by default, so a committed fixture would be
 * skipped by the very rule these tests exercise and every assertion made from in here would be vacuous.
 *
 * The disposition machinery is what is under test, NOT the shape test — that is consumed from
 * {@see UndescribedRegistryAudit} and has its own suite. What is asserted here is the part that is new:
 * which bucket a row lands in, what a write does to it, and what the three staleness checks say.
 */
class UndeclaredRegistryShapeAuditTest extends TestCase
{
    private ?string $root = null;

    private ?string $artifact = null;

    /** Class-name suffix keeping each test's planted classes distinct — a `require`d class cannot be undone. */
    private static int $plant = 0;

    protected function tearDown(): void
    {
        foreach ((array) glob(($this->root ?? '/nonexistent').'/*.php') as $file) {
            @unlink((string) $file);
        }

        if ($this->root !== null && is_dir($this->root)) {
            @rmdir($this->root);
        }

        if ($this->artifact !== null && is_file($this->artifact)) {
            @unlink($this->artifact);
        }

        parent::tearDown();
    }

    /**
     * Plant one registry-shaped, UNDECLARED class plus a provider that singleton-binds it, and return the
     * advisory audit scoped to that root.
     *
     * @param  array<string, string>  $whitelist
     * @param  (callable(string): ?bool)|null  $ticketStatus
     * @return array{0: UndeclaredRegistryShapeAudit, 1: string} audit, planted registry FQCN
     */
    private function plant(
        array $whitelist = [],
        $ticketStatus = null,
        string $declaration = '',
    ): array {
        $n = ++self::$plant;
        // "planted-shape" deliberately contains none of DEFAULT_EXCLUDED_PATHS' fragments.
        $this->root = sys_get_temp_dir().'/planted-shape-'.bin2hex(random_bytes(6));
        $this->artifact = sys_get_temp_dir().'/planted-artifact-'.bin2hex(random_bytes(6)).'.json';
        mkdir($this->root, 0777, true);

        $namespace = 'Splicewire\\Beam\\Tests\\PlantedShape'.$n;

        file_put_contents($registryFile = $this->root.'/PlantedShapeRegistry.php', <<<PHP
        <?php
        namespace {$namespace};
        {$declaration}class PlantedShapeRegistry{$n} {
            private array \$entries = [];
            public function register(string \$k, string \$v): void { \$this->entries[\$k] = \$v; }
            public function get(string \$k): ?string { return \$this->entries[\$k] ?? null; }
        }
        PHP);
        require $registryFile;

        file_put_contents($this->root.'/PlantedShapeProvider.php', <<<PHP
        <?php
        namespace {$namespace};
        use Illuminate\\Support\\ServiceProvider;
        class PlantedShapeProvider{$n} extends ServiceProvider {
            public function register(): void { \$this->app->singleton(PlantedShapeRegistry{$n}::class); }
        }
        PHP);

        $shape = new UndescribedRegistryAudit(
            [$this->root],
            $this->app->make(RegistryIndex::class),
            excludedPaths: [],
        );

        return [
            new UndeclaredRegistryShapeAudit($shape, (string) $this->artifact, $whitelist, $ticketStatus),
            $namespace.'\\PlantedShapeRegistry'.$n,
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function commit(array $rows): void
    {
        file_put_contents((string) $this->artifact, (string) json_encode(['registries' => $rows]));
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(UndeclaredRegistryShapeAudit $audit, string $fqcn): array
    {
        foreach ($audit->rows() as $row) {
            if ($row['registry'] === $fqcn) {
                return $row;
            }
        }

        $this->fail("The scan did not see {$fqcn} at all — every other assertion here would be vacuous.");
    }

    public function test_an_unrecorded_shape_is_unaccounted_not_outstanding(): void
    {
        [$audit, $registry] = $this->plant();

        // The distinction is the point of having a fourth value: `outstanding` is a row someone WROTE DOWN
        // and owes work on; `unaccounted` is one nobody has looked at. Collapsing them would let the
        // ratchet's baseline absorb new drift silently on its first run.
        $this->assertSame(UndeclaredRegistryShapeAudit::UNACCOUNTED, $this->rowFor($audit, $registry)['disposition']);
    }

    public function test_writing_the_artifact_promotes_unaccounted_to_outstanding(): void
    {
        [$audit, $registry] = $this->plant();

        $rows = $audit->artifactRows();

        $this->assertCount(1, $rows);
        $this->assertSame($registry, $rows[0]['registry']);
        $this->assertSame(UndeclaredRegistryShapeAudit::OUTSTANDING, $rows[0]['disposition']);
    }

    public function test_a_whitelisted_row_stays_out_of_the_artifact(): void
    {
        [, $registry] = $this->plant();
        // Re-scoped over the SAME planted root — re-planting would produce a different class and the
        // whitelist would then be keyed to something the second scan never sees.
        $audit = new UndeclaredRegistryShapeAudit(
            new UndescribedRegistryAudit([(string) $this->root], $this->app->make(RegistryIndex::class), excludedPaths: []),
            (string) $this->artifact,
            [$registry => 'argued permanent, for this test'],
        );

        // The whitelist lives in code so its argument can be read in review beside what it excuses; putting
        // it in the artifact would strip the argument and leave a row nobody can defend.
        $this->assertSame(UndeclaredRegistryShapeAudit::WHITELISTED, $this->rowFor($audit, $registry)['disposition']);
        $this->assertSame([], array_values(array_filter(
            $audit->artifactRows(),
            fn (array $row) => $row['registry'] === $registry,
        )));
    }

    public function test_a_committed_deferral_survives_a_rewrite(): void
    {
        [$audit, $registry] = $this->plant();
        $this->commit([[
            'registry' => $registry,
            'disposition' => UndeclaredRegistryShapeAudit::DEFERRED,
            'ticket' => 'some/effort/tickets/37-migrate.md',
        ]]);

        $row = $this->rowFor($audit, $registry);

        $this->assertSame(UndeclaredRegistryShapeAudit::DEFERRED, $row['disposition']);
        $this->assertSame('some/effort/tickets/37-migrate.md', $row['ticket']);
    }

    public function test_a_deferral_against_a_closed_ticket_is_stale(): void
    {
        [$audit, $registry] = $this->plant(ticketStatus: fn (string $ticket): ?bool => false);
        $this->commit([[
            'registry' => $registry,
            'disposition' => UndeclaredRegistryShapeAudit::DEFERRED,
            'ticket' => 'some/effort/tickets/37-migrate.md',
        ]]);

        $this->assertStringContainsString('CLOSED', implode("\n", $audit->staleness()));
    }

    public function test_a_deferral_against_an_open_ticket_is_not_stale(): void
    {
        [$audit, $registry] = $this->plant(ticketStatus: fn (string $ticket): ?bool => true);
        $this->commit([[
            'registry' => $registry,
            'disposition' => UndeclaredRegistryShapeAudit::DEFERRED,
            'ticket' => 'some/effort/tickets/37-migrate.md',
        ]]);

        $this->assertSame([], $audit->staleness());
    }

    public function test_an_unanswerable_ticket_is_counted_rather_than_resolved(): void
    {
        [$audit, $registry] = $this->plant(ticketStatus: fn (string $ticket): ?bool => null);
        $this->commit([[
            'registry' => $registry,
            'disposition' => UndeclaredRegistryShapeAudit::DEFERRED,
            'ticket' => 'some/effort/tickets/37-migrate.md',
        ]]);

        $stale = implode("\n", $audit->staleness());

        // Neither "stale" nor silence: defaulting to open hides every rotted deferral, defaulting to closed
        // reports every deferral as rotted on a host with no tracker checked out.
        $this->assertStringContainsString('UNCHECKED', $stale);
        $this->assertStringNotContainsString('CLOSED', $stale);
    }

    public function test_a_row_naming_a_vanished_class_is_stale(): void
    {
        [$audit] = $this->plant();
        $this->commit([[
            'registry' => 'Splicewire\\Beam\\Tests\\Nowhere\\GoneRegistry',
            'disposition' => UndeclaredRegistryShapeAudit::OUTSTANDING,
        ]]);

        $this->assertStringContainsString('no such class exists', implode("\n", $audit->staleness()));
    }

    public function test_a_row_whose_class_now_declares_itself_is_stale(): void
    {
        [$audit] = $this->plant();
        $this->commit([[
            'registry' => ConformingFixtureRegistry::class,
            'disposition' => UndeclaredRegistryShapeAudit::OUTSTANDING,
        ]]);

        $stale = implode("\n", $audit->staleness());

        $this->assertStringContainsString('now declares #[IsRegistry]', $stale);
        $this->assertStringContainsString(RegistryConformanceAudit::CHECK, $stale);
    }

    public function test_an_unaccounted_row_warns_and_never_fails(): void
    {
        [$audit] = $this->plant();
        $findings = $audit->run();

        $this->assertNotSame([], $findings);
        // Registered without `gate: true`, but the severity matters independently: this audit is where every
        // judgement call lives, and a judgement call that fails the build is one someone else made for you.
        foreach ($findings as $finding) {
            $this->assertNotSame(DoctorStatus::Fail, $finding->status);
        }
    }
}
