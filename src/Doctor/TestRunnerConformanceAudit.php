<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Surgeon\HouseStyleAudit;
use Splicewire\Beam\Surgeon\TablePrefixBypassAudit;

/**
 * Drift check for the fleet test-runner convention: family repos test with **Pest**.
 *
 * See `docs/agents/test-runner.convention.md` for the rule and the reasoning. This audit is the
 * detection half; there is deliberately no fix half.
 *
 * ## Why this is advisory, and will be for a while
 *
 * At the time of writing the estate is **71 Pest / 13 PHPUnit** — the convention describes what the
 * estate already overwhelmingly does, and the thirteen are a burn-down list, not a breakage. Beam core
 * **is one of the thirteen**, so this package ships an audit it currently fails. That is intentional and
 * has precedent ({@see TablePrefixBypassAudit} is likewise an advisory
 * burn-down whose count is expected to be non-zero); an audit that only ever reports zero teaches a host
 * nothing about where the estate is actually heading.
 *
 * ## Why there is no surgeon operation paired with it
 *
 * {@see HouseStyleAudit} pairs with a deterministic strip operation because its
 * remedy is a DELETION — remove `final`, `readonly`, `strict_types`. A PHPUnit→Pest conversion is a
 * restructuring: `setUp()` becomes `beforeEach()`, test methods become closures, `$this->assertSame()`
 * becomes `expect()->toBe()`, and fixture classes and helper functions hoist to file scope. Each of those
 * is a judgement call in the general case. Reporting is honest; a token-aware auto-rewrite would not be.
 *
 * ## Reach
 *
 * Repo-LOCAL by construction. It reads the manifest of the repo it is booted in, never the estate's —
 * `AuditScanPaths` is explicit that the audit seam "is not a fleet-wide census", and nothing about a
 * host's own composer file licenses a claim about a sibling package. Every repo answers for itself.
 */
class TestRunnerConformanceAudit implements DoctorAudit
{
    private const CHECK = 'tests run on Pest (fleet convention)';

    public function __construct(private ?string $basePath = null) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $manifest = $this->manifest();

        if ($manifest === null) {
            return [Finding::warn(self::CHECK, 'No readable composer.json at the repo root — cannot determine the test runner.')];
        }

        $dev = (array) ($manifest['require-dev'] ?? []);
        $require = (array) ($manifest['require'] ?? []);
        $declared = $dev + $require;

        $hasPest = isset($declared['pestphp/pest']);
        $hasPhpunit = isset($declared['phpunit/phpunit']);

        if (! is_dir($this->path('tests'))) {
            return [Finding::pass(self::CHECK, 'No tests/ directory — nothing to run, so the convention does not apply here.')];
        }

        if ($hasPest) {
            return [$this->pestIsWiredThrough()];
        }

        if ($hasPhpunit) {
            return [Finding::warn(
                self::CHECK,
                'This repo tests on PHPUnit; the fleet convention is Pest (see docs/agents/test-runner.convention.md '.
                'in splicewire/laravel-beam). Advisory — a burn-down item, not a breakage. Note Pest runs PHPUnit test '.
                'classes unchanged, so adopting it is additive: require pestphp/pest, add tests/Pest.php, and point the '.
                'composer `test` script at vendor/bin/pest. Converting the existing classes to test() closures can follow '.
                'separately, file by file.',
            )];
        }

        return [Finding::warn(
            self::CHECK,
            'A tests/ directory exists but neither pestphp/pest nor phpunit/phpunit is declared — the suite has no '.
            'declared runner, so nothing here can be run reproducibly by CI or by another agent.',
        )];
    }

    /**
     * Declaring Pest is not the same as running it. A repo that requires Pest but whose `test` script
     * still invokes phpunit runs the PHPUnit binary, which silently ignores every `test()`/`it()` closure
     * — a suite that reports success while executing a fraction of itself.
     */
    private function pestIsWiredThrough(): Finding
    {
        $manifest = (array) $this->manifest();
        $script = $manifest['scripts']['test'] ?? null;
        $script = is_array($script) ? implode(' ', $script) : (string) $script;

        $problems = [];

        if (! file_exists($this->path('tests/Pest.php'))) {
            $problems[] = 'no tests/Pest.php (Pest binds its TestCase per-directory there — without it, tests run against the bare PHPUnit case)';
        }

        if ($script !== '' && str_contains($script, 'phpunit')) {
            $problems[] = 'the composer `test` script still invokes phpunit, which ignores every test()/it() closure silently';
        }

        if ($problems !== []) {
            return Finding::warn(
                self::CHECK,
                'Pest is required but not fully wired through: '.implode('; ', $problems).'.',
            );
        }

        return Finding::pass(self::CHECK, 'Tests run on Pest, wired through tests/Pest.php and the composer `test` script.');
    }

    /** @return array<string, mixed>|null */
    private function manifest(): ?array
    {
        $path = $this->path('composer.json');

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function path(string $relative): string
    {
        $base = $this->basePath ?? base_path();

        return rtrim($base, '/').'/'.$relative;
    }
}
