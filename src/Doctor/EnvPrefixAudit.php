<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Console\EnvPrefixBaselineCommand;
use Splicewire\Beam\Doctor\Support\EnvPrefixCensus;

/**
 * **A new env key carries its package's prefix** (env-inventory 02; ecosystem `docs/conventions/env-entries.md`). For
 * every `env()` call in an installed family package's `config/**\/*.php` ({@see EnvPrefixCensus}):
 *
 * - **the rule (fail):** the name starts with its file's prefix (`config/beam/ux.php` → `BEAM_UX_`), is a declared
 *   third-party name ({@see EnvPrefixCensus::THIRD_PARTY}), or is in the package's committed baseline
 *   ({@see EnvPrefixCensus::BASELINE}). A package without a baseline has every non-conforming name reported;
 * - **another package's prefix (warn):** the longest prefix an installed package's config file owns that the name
 *   starts with belongs to a different package (`COMMERCE_WEBHOOK_PATH` read by beam lands in the `COMMERCE_` that
 *   `rushing/laravel-commerce` owns). A grandfathered or third-party name is not reported here: grandfathering accepts
 *   an existing name as it is (the convention renames one only when its config is already changing), so a package
 *   whose baseline was just written reads clean. This is a reading of the ticket's scope 3, stated here;
 * - **two defaults (warn):** one name read by two packages whose `env()` defaults differ, so the value a host gets
 *   depends on which config it reads (`POPCORN_BUBBLE_ALLOW_UNSANDBOXED` is the specimen). The baseline does not
 *   silence this one: it is a behaviour difference, not a spelling;
 * - **a stale baseline (warn):** a baselined name the package no longer reads. Left there, the name could come back
 *   unnoticed; {@see EnvPrefixBaselineCommand} shrinks the file.
 *
 * Runs the same in a package checkout (the root package and its family dependencies) and in a host (every installed
 * family package; the host's own config is out of scope). It only reads: the baseline is written by
 * {@see EnvPrefixBaselineCommand} and nothing else. Advisory; config-sections 13 is where the config audits start to
 * gate.
 */
class EnvPrefixAudit implements DoctorAudit
{
    public const CHECK = 'config.env-prefix';

    private const SHOWN = 5;

    public function __construct(
        protected EnvPrefixCensus $census,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $packages = $this->census->packages();

        if ($packages === []) {
            return [Finding::inconclusive(self::CHECK, 'No family package (splicewire, rushing, schemastud) is installed, so no env() name was read.')];
        }

        $scans = [];
        $owners = [];

        foreach ($packages as $package => $root) {
            $scans[$package] = EnvPrefixCensus::readsOf($package, $root);

            foreach ($scans[$package]['prefixes'] as $prefix) {
                $owners[$prefix][] = $package;
            }
        }

        $findings = [];
        $counts = ['files' => 0, 'reads' => 0, 'dynamic' => 0, 'grandfathered' => 0];

        foreach ($scans as $package => $scan) {
            $counts['files'] += $scan['files'];
            $counts['reads'] += count($scan['reads']);
            $counts['dynamic'] += $scan['dynamic'];

            array_push($findings, ...$this->package($package, $packages[$package], $scan, $owners, $counts));
        }

        array_push($findings, ...$this->disagreements($scans));

        if ($findings !== []) {
            return $findings;
        }

        return [Finding::pass(self::CHECK, sprintf(
            '%d family package(s), %d config file(s), %d env() read(s): every name starts with its file\'s prefix, is a declared third-party name, or is in its package\'s baseline (%d grandfathered); none sits in another package\'s prefix, and none is read with two defaults.%s',
            count($packages),
            $counts['files'],
            $counts['reads'],
            $counts['grandfathered'],
            $counts['dynamic'] > 0 ? sprintf(' %d call(s) with a computed name were counted, not checked.', $counts['dynamic']) : '',
        ))];
    }

    /**
     * @param  array{reads: list<array{package: string, file: string, prefix: string, binding: \Splicewire\Beam\Config\ConfigEnvBinding}>, prefixes: list<string>, files: int, dynamic: int, unreadable: list<string>}  $scan
     * @param  array<string, list<string>>  $owners
     * @param  array{files: int, reads: int, dynamic: int, grandfathered: int}  $counts
     * @return list<Finding>
     */
    protected function package(string $package, string $root, array $scan, array $owners, array &$counts): array
    {
        $baseline = EnvPrefixCensus::baseline($root);
        $listed = array_flip($baseline ?? []);
        $unruled = [];
        $foreign = [];
        $read = [];

        foreach ($scan['reads'] as $entry) {
            $name = $entry['binding']->name;
            $where = $entry['file'].($entry['binding']->line !== null ? ':'.$entry['binding']->line : '');
            $read[$name] = true;

            if (isset($listed[$name])) {
                continue;
            }

            if (! EnvPrefixCensus::conforms($name, $entry['prefix'])) {
                $unruled[$name] ??= "{$name} ({$where})";
            }

            $owner = in_array($name, EnvPrefixCensus::THIRD_PARTY, true) ? null : $this->owningPrefix($name, $owners);

            if ($owner !== null && ! in_array($package, $owners[$owner], true)) {
                $foreign[$name] ??= sprintf('%s (%s; %s owns %s)', $name, $where, implode(', ', $owners[$owner]), $owner);
            }
        }

        $counts['grandfathered'] += count(array_intersect_key($listed, $read));
        $findings = [];

        if ($unruled !== []) {
            $findings[] = Finding::fail(self::CHECK, sprintf(
                '%s: %d env name(s) start with neither their config file\'s prefix nor a declared third-party name, and %s: %s. Name a new key with its file\'s prefix; the baseline only shrinks.',
                $package,
                count($unruled),
                $baseline === null
                    ? 'it has no '.EnvPrefixCensus::BASELINE.' (in a host that links it from its own checkout, `php artisan splicewire:beam:env-prefix-baseline --package='.$package.' --write` captures today\'s names)'
                    : 'are not in its '.EnvPrefixCensus::BASELINE,
                $this->listed(array_values($unruled)),
            ));
        }

        if ($foreign !== []) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%s: %d env name(s) sit in another installed package\'s prefix, so the two share one variable: %s. Read the owner\'s config key instead, or name the key with this package\'s own prefix.',
                $package,
                count($foreign),
                $this->listed(array_values($foreign)),
            ));
        }

        $stale = array_values(array_filter($baseline ?? [], static fn (string $name): bool => ! isset($read[$name])));

        if ($stale !== []) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%s: %d name(s) in its %s are no longer read by its config: %s. Shrink it in a host that links it from its own checkout: `php artisan splicewire:beam:env-prefix-baseline --package=%s --write`.',
                $package,
                count($stale),
                EnvPrefixCensus::BASELINE,
                $this->listed($stale),
                $package,
            ));
        }

        if ($scan['unreadable'] !== []) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%s: %d config file(s) did not parse, so their env() names were not read: %s',
                $package,
                count($scan['unreadable']),
                $this->listed($scan['unreadable']),
            ));
        }

        return $findings;
    }

    /**
     * One name read by two or more packages with different defaults.
     *
     * @param  array<string, array{reads: list<array{package: string, file: string, prefix: string, binding: \Splicewire\Beam\Config\ConfigEnvBinding}>, prefixes: list<string>, files: int, dynamic: int, unreadable: list<string>}>  $scans
     * @return list<Finding>
     */
    protected function disagreements(array $scans): array
    {
        $byName = [];

        foreach ($scans as $package => $scan) {
            foreach ($scan['reads'] as $entry) {
                $default = $this->normalizedDefault($entry['binding']->default);
                $where = $entry['file'].($entry['binding']->line !== null ? ':'.$entry['binding']->line : '');
                $byName[$entry['binding']->name][$package][$default] ??= "{$package} {$where} → {$default}";
            }
        }

        ksort($byName);
        $findings = [];

        foreach ($byName as $name => $readers) {
            $defaults = array_unique(array_merge(...array_map('array_keys', array_values($readers))));

            if (count($readers) < 2 || count($defaults) < 2) {
                continue;
            }

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%s is read by %d packages with different defaults, so a host that leaves it unset gets a value that depends on which config it reads: %s. Read it in the owning package only.',
                $name,
                count($readers),
                implode('; ', array_merge(...array_map('array_values', array_values($readers)))),
            ));
        }

        return $findings;
    }

    /**
     * A default as compared across packages: no second argument is `null` (what `env()` returns then), the constants
     * `true`/`false`/`null` are lowercased, and a plain string literal is single-quoted whichever quotes it was written
     * with. Anything else (an expression, `1` against `true`) stays as printed, so it may still report a pair that
     * behaves alike.
     */
    private function normalizedDefault(?string $default): string
    {
        if ($default === null) {
            return 'null';
        }

        $lower = strtolower($default);

        if (in_array($lower, ['true', 'false', 'null'], true)) {
            return $lower;
        }

        if (preg_match('/^"([^"\\\\$\']*)"$/', $default, $match) === 1) {
            return "'".$match[1]."'";
        }

        return $default;
    }

    /**
     * The longest owned prefix the name starts with, or null when none does.
     *
     * @param  array<string, list<string>>  $owners
     */
    private function owningPrefix(string $name, array $owners): ?string
    {
        $best = null;

        foreach (array_keys($owners) as $prefix) {
            if (str_starts_with($name, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best;
    }

    /** @param list<string> $items */
    private function listed(array $items): string
    {
        $shown = implode(', ', array_slice($items, 0, self::SHOWN));

        return count($items) > self::SHOWN ? $shown.sprintf(' (+%d more)', count($items) - self::SHOWN) : $shown;
    }
}
