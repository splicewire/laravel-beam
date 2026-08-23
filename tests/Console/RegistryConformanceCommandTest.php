<?php

namespace Splicewire\Beam\Tests\Console;

use Illuminate\Console\Command;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Console\RegistryConformanceCommand;
use Splicewire\Beam\Doctor\UndeclaredRegistryShapeAudit;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;
use Splicewire\Beam\Tests\Doctor\UndeclaredRegistryShapeAuditTest;
use Splicewire\Beam\Tests\TestCase;

/**
 * registry-kernel ticket 35 §3 — the ratchet command.
 *
 * The property under test is the RATCHET, not the report: an artifact whose number can go up is a number,
 * not a ratchet, and the whole reason this command exists rather than a `--json` flag on the audit is that
 * the artifact gets committed and reviewed. So the cases here are write → check-green → drift →
 * check-red, in that order, against a real file on disk.
 *
 * The planted-root pattern and its reasoning are {@see UndeclaredRegistryShapeAuditTest}'s.
 */
class RegistryConformanceCommandTest extends TestCase
{
    private string $root;

    private string $artifact;

    private static int $plant = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // "planted-cmd" deliberately contains none of DEFAULT_EXCLUDED_PATHS' fragments.
        $this->root = sys_get_temp_dir().'/planted-cmd-'.bin2hex(random_bytes(6));
        $this->artifact = sys_get_temp_dir().'/planted-cmd-artifact-'.bin2hex(random_bytes(6)).'.json';
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/*.php') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->root);
        @unlink($this->artifact);

        parent::tearDown();
    }

    /** Plant one registry-shaped, undeclared class plus the provider that binds it. */
    private function plant(): string
    {
        $n = ++self::$plant;
        $namespace = 'Splicewire\\Beam\\Tests\\PlantedCmd'.$n;

        file_put_contents($file = $this->root.'/PlantedCmdRegistry'.$n.'.php', <<<PHP
        <?php
        namespace {$namespace};
        class PlantedCmdRegistry{$n} {
            private array \$entries = [];
            public function register(string \$k, string \$v): void { \$this->entries[\$k] = \$v; }
            public function get(string \$k): ?string { return \$this->entries[\$k] ?? null; }
        }
        PHP);
        require $file;

        file_put_contents($this->root.'/PlantedCmdProvider'.$n.'.php', <<<PHP
        <?php
        namespace {$namespace};
        use Illuminate\\Support\\ServiceProvider;
        class PlantedCmdProvider{$n} extends ServiceProvider {
            public function register(): void { \$this->app->singleton(PlantedCmdRegistry{$n}::class); }
        }
        PHP);

        return $namespace.'\\PlantedCmdRegistry'.$n;
    }

    /** Rebind the advisory audit onto the planted root — the command resolves it from the container. */
    private function rebind(): void
    {
        $this->app->instance(UndeclaredRegistryShapeAudit::class, new UndeclaredRegistryShapeAudit(
            new UndescribedRegistryAudit([$this->root], $this->app->make(RegistryIndex::class), excludedPaths: []),
            $this->artifact,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function committed(): array
    {
        return (array) json_decode((string) file_get_contents($this->artifact), true);
    }

    public function test_check_refuses_to_run_before_the_artifact_exists(): void
    {
        $this->rebind();

        // Not a pass. A missing artifact is "nobody has ever measured this", and treating that as green is
        // how a ratchet gets adopted without ever having a baseline.
        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--check' => true, '--path' => $this->artifact])
            ->assertExitCode(Command::FAILURE);
    }

    public function test_a_write_records_every_shape_as_outstanding_and_then_checks_green(): void
    {
        $registry = $this->plant();
        $this->rebind();

        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--path' => $this->artifact])
            ->assertExitCode(Command::SUCCESS);

        $committed = $this->committed();

        $this->assertSame(1, $committed['counts'][UndeclaredRegistryShapeAudit::OUTSTANDING]);
        $this->assertSame(0, $committed['counts'][UndeclaredRegistryShapeAudit::UNACCOUNTED]);
        $this->assertSame($registry, $committed['registries'][0]['registry']);

        $this->rebind();
        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--check' => true, '--path' => $this->artifact])
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_a_no_op_rerun_is_byte_identical(): void
    {
        $this->plant();
        $this->rebind();

        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--path' => $this->artifact])->run();
        $first = (string) file_get_contents($this->artifact);

        $this->rebind();
        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--path' => $this->artifact])->run();

        // Byte-identical or the artifact is unreviewable: a diff that churns on filesystem iteration order
        // trains reviewers to skip it, which is the one thing a committed ratchet cannot survive.
        $this->assertSame($first, (string) file_get_contents($this->artifact));
    }

    public function test_a_new_shape_fails_the_check_and_is_named(): void
    {
        $this->plant();
        $this->rebind();
        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--path' => $this->artifact])->run();

        $drift = $this->plant();
        $this->rebind();

        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--check' => true, '--path' => $this->artifact])
            ->expectsOutputToContain('INCREASED')
            // Named, not just counted: a failure that reports "2 → 3" without saying which one is new is a
            // score, and a score is not a work-list.
            ->expectsOutputToContain($drift)
            ->assertExitCode(Command::FAILURE);
    }

    public function test_a_decrease_passes_without_forcing_a_rewrite_commit(): void
    {
        $this->plant();
        $this->plant();
        $this->rebind();
        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--path' => $this->artifact])->run();

        // Simulate one of them being migrated away by narrowing the scan to a root holding neither.
        $this->app->instance(UndeclaredRegistryShapeAudit::class, new UndeclaredRegistryShapeAudit(
            new UndescribedRegistryAudit([$this->root.'/nowhere'], $this->app->make(RegistryIndex::class), excludedPaths: []),
            $this->artifact,
        ));

        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--check' => true, '--path' => $this->artifact])
            ->expectsOutputToContain('decreased')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_json_writes_nothing(): void
    {
        $this->plant();
        $this->rebind();

        $this->artisan(RegistryConformanceCommand::SIGNATURE, ['--json' => true, '--path' => $this->artifact])
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileDoesNotExist($this->artifact);
    }
}
