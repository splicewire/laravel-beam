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

        return $this->option('check')
            ? $this->check($path, $rows)
            : $this->write($path, $rows);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function write(string $path, array $rows): int
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $this->encode($rows));

        $this->info(sprintf('Wrote %d undeclared surface(s) to %s', count($rows), $path));
        $this->table(['tier', 'count'], $this->tally($rows));

        return self::SUCCESS;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function check(string $path, array $rows): int
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
     * @param  list<array<string, mixed>>  $rows
     */
    private function encode(array $rows): string
    {
        return json_encode([
            'check' => UndeclaredSurfaceAudit::CHECK,
            'count' => count($rows),
            'surfaces' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
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

    private function artifactPath(): string
    {
        $configured = $this->option('path')
            ?? config('beam.client.undeclared_surface_artifact')
            ?? base_path('.beam/undeclared-surface.json');

        return (string) $configured;
    }
}
