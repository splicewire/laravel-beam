<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;

/**
 * Writes and checks the **committed** undeclared-surface artifact — the ratchet that turns a
 * several-hundred-endpoint problem into a burn-down instead of a wall.
 *
 * The artifact is the point, not a side effect. A number that lives only in a command's output is a number
 * nobody is accountable to; committed, it appears as a diff in review and can only move one way. So this
 * command has two modes and CI uses the second:
 *
 *   - default — rewrite the artifact from the live route table (`--write`);
 *   - `--check` — recompute and compare against the committed artifact, failing when the count has GONE UP.
 *
 * `--check` fails on an increase, not on any difference. A decrease is the whole goal, and forcing a rewrite
 * commit for every improvement would make the ratchet an obstacle to the thing it exists to encourage — so a
 * drop reports what it dropped by and passes, leaving the rewrite to the author's own commit.
 */
class UndeclaredSurfaceCommand extends Command
{
    protected $signature = 'splicewire:beam:undeclared-surface
        {--check : Compare against the committed artifact and fail if the count increased; writes nothing}
        {--path= : Artifact path (default: the configured one)}';

    protected $description = 'Write (or check) the committed artifact recording every live surface that declares no shape.';

    public function handle(UndeclaredSurfaceAudit $audit): int
    {
        $path = $this->artifactPath();
        $rows = $audit->undeclared();
        $excluded = $audit->excludedDevOnly();

        return $this->option('check')
            ? $this->check($path, $rows, $excluded)
            : $this->write($path, $rows, $excluded);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $excluded
     */
    private function write(string $path, array $rows, array $excluded): int
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $this->encode($rows, $excluded));

        $this->info(sprintf('Wrote %d undeclared surface(s) to %s', count($rows), $path));
        $this->table(['tier', 'count'], $this->tally($rows));

        if ($excluded !== []) {
            $this->line(sprintf(
                '  %d dev-only row(s) excluded from the population (%s).',
                count($excluded),
                implode(', ', array_keys($this->countBy($excluded, 'origin'))),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $excluded
     */
    private function check(string $path, array $rows, array $excluded): int
    {
        if (! is_file($path)) {
            $this->error(sprintf('No committed artifact at %s — run this command without --check to create it.', $path));

            return self::FAILURE;
        }

        $committed = json_decode((string) file_get_contents($path), true);
        $was = is_array($committed['surfaces'] ?? null) ? count($committed['surfaces']) : 0;
        $now = count($rows);

        if ($now > $was) {
            $this->error(sprintf('Undeclared surface INCREASED: %d → %d. The artifact may only ratchet downward.', $was, $now));

            foreach (array_slice($this->newlyUndeclared($committed['surfaces'] ?? [], $rows), 0, 20) as $uri) {
                $this->line("  + {$uri}");
            }

            $this->reportOriginDelta($committed, $rows);

            return self::FAILURE;
        }

        $this->info($now < $was
            ? sprintf('Undeclared surface decreased: %d → %d. Commit the regenerated artifact.', $was, $now)
            : sprintf('Undeclared surface holding at %d.', $now));

        return self::SUCCESS;
    }

    /**
     * Which surfaces are in the recomputed set but not the committed one — so a failure is a work-list naming
     * what was just added, rather than only a count that went the wrong way.
     *
     * @param  list<array<string, mixed>>  $committed
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function newlyUndeclared(array $committed, array $rows): array
    {
        $before = [];

        foreach ($committed as $row) {
            $before[($row['uri'] ?? '').' '.implode('|', $row['methods'] ?? [])] = true;
        }

        $new = [];

        foreach ($rows as $row) {
            $key = $row['uri'].' '.implode('|', $row['methods']);

            if (! isset($before[$key])) {
                $new[] = $key;
            }
        }

        return $new;
    }

    /**
     * The artifact body. `count` is stored beside the rows so a reviewer reads the number off the diff header
     * without counting entries, and the trailing newline keeps it a well-formed committed text file.
     *
     * ## `counts` and `excluded` are not decoration (beam-facade ticket 140)
     * The artifact recorded a **scalar**, and that scalar is what let two sessions independently
     * misattribute a 356 → 433 move to `api/v1/beam/accounts/*` — when only 15 of the 433 rows contained
     * `beam/accounts` at all and 226 came from `splicewire/tower`. The composition was derivable from the
     * rows the whole time; nothing recorded it, so nobody derived it. A delta must be able to name its own
     * cause off the diff header, which is the only part of a 3,500-line artifact anyone reads.
     *
     * `excluded` records what was held OUT and why. An exclusion nobody can see is indistinguishable from
     * a check that quietly stopped looking, which is the failure mode this whole ticket is an instance of.
     *
     * `baseline` states the environment the number was taken in, so a disagreement between two machines is
     * readable rather than mysterious. `dev_dependencies_installed` is the load-bearing field: it is false
     * under `composer install --no-dev`, and a comparison across that boundary is comparing two different
     * populations even though dev rows are excluded from the count either way (their *absence* also
     * removes any production route a dev-only package's provider happened to mount).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $excluded
     */
    private function encode(array $rows, array $excluded): string
    {
        return json_encode([
            'check' => UndeclaredSurfaceAudit::CHECK,
            'count' => count($rows),
            'baseline' => [
                'environment' => (string) app()->environment(),
                'php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                'dev_dependencies_installed' => is_file(base_path('vendor/composer/installed.json'))
                    && str_contains((string) file_get_contents(base_path('vendor/composer/installed.json')), '"dev-package-names"'),
                'generated_by' => 'splicewire:beam:undeclared-surface',
            ],
            'counts' => [
                'by_tier' => $this->countBy($rows, 'tier'),
                'by_origin' => $this->countBy($rows, 'origin'),
            ],
            'excluded' => [
                'dev_only' => [
                    'count' => count($excluded),
                    'reason' => 'The package shipping the action is require-dev, so the row is present or '
                        .'absent depending on --no-dev and on the developer\'s own tooling. A ratchet exists '
                        .'to be compared across two machines; an environment-dependent number cannot be.',
                    'packages' => array_keys($this->countBy($excluded, 'origin')),
                ],
                'production_vendor' => [
                    'policy' => 'IN',
                    'reason' => 'They ship, a caller can hit them, and mounting them was a host composition '
                        .'decision (see beam-facade ticket 119). Bucketed by origin rather than dropped, so a '
                        .'delta names its own cause.',
                ],
            ],
            'surfaces' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countBy(array $rows, string $field): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $key = (string) ($row[$field] ?? 'unknown');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{0: string, 1: int}>
     */
    private function tally(array $rows): array
    {
        $counts = [
            UndeclaredSurfaceAudit::TIER_MECHANICAL => 0,
            UndeclaredSurfaceAudit::TIER_GUIDED => 0,
            UndeclaredSurfaceAudit::TIER_MANUAL => 0,
        ];

        foreach ($rows as $row) {
            $counts[$row['tier']]++;
        }

        return array_map(null, array_keys($counts), array_values($counts));
    }

    /**
     * Which ORIGINS moved, printed under a failure — the half a scalar could never give.
     *
     * A `--check` that says only "356 → 433" sends its reader hunting, and the estate has the receipts:
     * two sessions hunted independently and both landed on the wrong package. Naming the origins that grew
     * turns the failure into an address.
     *
     * @param  array<string, mixed>  $committed
     * @param  list<array<string, mixed>>  $rows
     */
    private function reportOriginDelta(array $committed, array $rows): void
    {
        $before = $this->countBy(is_array($committed['surfaces'] ?? null) ? $committed['surfaces'] : [], 'origin');
        $after = $this->countBy($rows, 'origin');

        foreach ($after as $origin => $count) {
            $was = $before[$origin] ?? 0;

            if ($count > $was) {
                $this->line(sprintf('  %s: %d → %d', $origin, $was, $count));
            }
        }
    }

    private function artifactPath(): string
    {
        $configured = $this->option('path')
            ?: config('beam.client.undeclared_surface_artifact')
            ?: base_path('.beam/undeclared-surface.json');

        return (string) $configured;
    }
}
