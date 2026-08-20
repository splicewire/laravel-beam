<?php

namespace Splicewire\Beam\Tests\Doctor;

use Splicewire\Beam\Doctor\TestRunnerConformanceAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * The fleet test-runner convention check (docs/agents/test-runner.convention.md).
 *
 * The interesting assertion is not "PHPUnit warns" — it is that **declaring Pest is not the same as
 * running it**. A repo that requires Pest but whose composer `test` script still invokes phpunit runs the
 * PHPUnit binary, which does not error on Pest files; it collects nothing from them and reports success.
 * A suite that passes while executing a fraction of itself is the failure this audit exists to catch.
 */
class TestRunnerConformanceAuditTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/beam-runner-audit-'.bin2hex(random_bytes(6));
        mkdir($this->base.'/tests', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->base.'/tests/*') as $file) {
            is_string($file) && @unlink($file);
        }
        @unlink($this->base.'/composer.json');
        @rmdir($this->base.'/tests');
        @rmdir($this->base);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $manifest */
    private function manifest(array $manifest): void
    {
        file_put_contents($this->base.'/composer.json', json_encode($manifest));
    }

    private function audit(): array
    {
        return (new TestRunnerConformanceAudit($this->base))->run();
    }

    public function test_a_fully_wired_pest_repo_passes(): void
    {
        $this->manifest([
            'require-dev' => ['pestphp/pest' => '^3.0'],
            'scripts' => ['test' => '@php vendor/bin/pest'],
        ]);
        touch($this->base.'/tests/Pest.php');

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertSame('pass', $findings[0]->status->value ?? 'pass');
    }

    public function test_a_phpunit_repo_warns_and_names_the_additive_path(): void
    {
        $this->manifest(['require-dev' => ['phpunit/phpunit' => '^11.0']]);

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('fleet convention is Pest', $findings[0]->detail);
        // The remedy must read as additive, or the audit reads as "rewrite your suite".
        $this->assertStringContainsString('runs PHPUnit test classes unchanged', $findings[0]->detail);
    }

    public function test_requiring_pest_while_the_test_script_still_runs_phpunit_warns(): void
    {
        $this->manifest([
            'require-dev' => ['pestphp/pest' => '^3.0'],
            'scripts' => ['test' => '@php vendor/bin/phpunit'],
        ]);
        touch($this->base.'/tests/Pest.php');

        $findings = $this->audit();

        $this->assertStringContainsString('still invokes phpunit', $findings[0]->detail);
        $this->assertStringContainsString('silently', $findings[0]->detail);
    }

    public function test_pest_without_a_pest_php_warns(): void
    {
        $this->manifest([
            'require-dev' => ['pestphp/pest' => '^3.0'],
            'scripts' => ['test' => '@php vendor/bin/pest'],
        ]);

        $this->assertStringContainsString('no tests/Pest.php', $this->audit()[0]->detail);
    }

    public function test_a_repo_with_no_tests_directory_is_not_asked_to_adopt_anything(): void
    {
        $this->manifest(['require-dev' => ['phpunit/phpunit' => '^11.0']]);
        rmdir($this->base.'/tests');

        $this->assertStringContainsString('nothing to run', $this->audit()[0]->detail);
    }

    public function test_a_tests_directory_with_no_declared_runner_warns(): void
    {
        $this->manifest(['require-dev' => []]);

        $this->assertStringContainsString('no declared runner', $this->audit()[0]->detail);
    }
}
