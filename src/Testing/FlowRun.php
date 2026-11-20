<?php

namespace Splicewire\Beam\Testing;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Splicewire\Beam\Flows\FlowCatalog;

/**
 * The orchestration behind the {@see flow()} helper, kept off the global function so beam's own Pest suite
 * can drive it with a real {@see TestCase} (browser-journeys 10). For one shipped browser test it:
 * validates the key and role against the `beam.flows` declaration, requires the host test case to carry a
 * reset trait, writes one {@see FlowResultData} row to the run's gitignored `flow-results.jsonl`, SKIPS the
 * test with the covering `BEAM.md` Deviation's reason when one applies, and returns the {@see FlowScreenshot}
 * name. It only names the capture — pest-plugin-browser takes it; committing a baseline is ticket 17.
 */
final class FlowRun
{
    /** Laravel's four database-reset traits; the host browser test case must use one (ticket 04 R9). */
    private const RESET_TRAITS = [
        'Illuminate\Foundation\Testing\RefreshDatabase',
        'Illuminate\Foundation\Testing\DatabaseTransactions',
        'Illuminate\Foundation\Testing\DatabaseMigrations',
        'Illuminate\Foundation\Testing\DatabaseTruncation',
    ];

    public static function record(TestCase $test, string $key, ?string $role, int $width): FlowScreenshot
    {
        Flow::assertValidKey($key);

        $catalog = app(FlowCatalog::class);
        $declarations = $catalog->declarations($key);

        if ($declarations === []) {
            throw new InvalidArgumentException(
                "flow '{$key}' is not declared in this host's beam.flows catalog — a shipped test names only a declared flow."
            );
        }

        Flow::assertRoleDeclared($declarations[0], $role);
        self::assertResetTrait($test::class);

        $covering = beam_manifest()->covering($key);
        $reason = $covering !== null
            ? (is_string($covering['note'] ?? null) ? trim((string) $covering['note']) : "flow '{$key}' is declared off in BEAM.md.")
            : null;

        self::append($test, new FlowResultData(
            flow: $key,
            role: $role,
            package: $catalog->registrantOf($key) ?? 'unknown',
            file: self::fileOf($test),
            test: $test->nameWithDataSet(),
            outcome: $covering !== null ? FlowOutcome::SkippedDeviation : FlowOutcome::Entered,
            reason: $reason,
        ));

        $screenshot = new FlowScreenshot(Flow::screenshotName($key, $role, $width));

        if ($covering !== null) {
            Assert::markTestSkipped($reason);
        }

        return $screenshot;
    }

    /** The host test case must use one of Laravel's four reset traits (ticket 04 R9). */
    public static function assertResetTrait(string $testCaseClass): void
    {
        $traits = array_keys(class_uses_recursive($testCaseClass));

        if (array_intersect($traits, self::RESET_TRAITS) === []) {
            throw new InvalidArgumentException(
                "the browser test case {$testCaseClass} uses none of Laravel's reset traits (".
                implode(', ', array_map(static fn (string $t): string => class_basename($t), self::RESET_TRAITS)).
                ') — the flow helper requires one so shipped suites do not each declare their own.'
            );
        }
    }

    /** The run-scoped, gitignored results file beside the screenshots: `tests/Browser/Screenshots/flow-results.jsonl`. */
    public static function resultsPath(): string
    {
        return base_path('tests/Browser/Screenshots/flow-results.jsonl');
    }

    private static function append(TestCase $test, FlowResultData $row): void
    {
        $path = self::resultsPath();

        if (! is_dir($dir = dirname($path))) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($row->toArray(), JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }

    /** The file the join key (ticket 02 U1) reads; measured against JUnit's `testcase file` by 10's proof. */
    private static function fileOf(TestCase $test): string
    {
        return (new ReflectionClass($test))->getFileName() ?: '';
    }
}
