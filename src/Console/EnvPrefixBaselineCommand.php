<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Splicewire\Beam\Doctor\EnvPrefixAudit;
use Splicewire\Beam\Doctor\Support\EnvPrefixCensus;

/**
 * `splicewire:beam:env-prefix-baseline` — writes the package's grandfathered env names
 * ({@see EnvPrefixCensus::BASELINE}, at the package root) for {@see EnvPrefixAudit} (env-inventory 02).
 *
 * Regeneration is this command; the audit and its test only read the file (ecosystem
 * `docs/conventions/regenerating-committed-artifacts.md`). Without `--write` it prints what it would write.
 *
 * - **First write captures.** With no baseline, every name in the package's config that breaks the rule today is
 *   grandfathered, which is the convention's ruling for existing names.
 * - **Later writes only shrink.** A name that is no longer read leaves; a non-conforming name that is not already in the
 *   baseline refuses the write and is named, because a new key takes its file's prefix rather than joining the list. A
 *   baseline git still tracks but the checkout lacks refuses too, so deleting the file cannot reopen the capture; past
 *   that, the reviewed diff of the committed file is the guard.
 * - **Only into a package's own repository.** It writes for Composer's root package (run in the package's checkout),
 *   or, with `--package`, for an installed family package whose root resolves to a git checkout outside any `vendor/`
 *   directory (an overlay link). That is how a `rushing/*` or `schemastud/*` package, which does not install beam, gets
 *   its baseline. A vendor copy refuses: the file is committed where the package is developed.
 */
class EnvPrefixBaselineCommand extends Command
{
    protected $signature = 'splicewire:beam:env-prefix-baseline
                            {--package= : An installed family package linked from its own git checkout (default: this checkout\'s package)}
                            {--write : Write the baseline (without it, print what would be written)}';

    protected $description = 'Write (or preview) a package\'s env-prefix baseline: its grandfathered env names, which may only shrink.';

    public function handle(EnvPrefixCensus $census): int
    {
        $option = $this->option('package');
        $package = is_string($option) && $option !== '' ? $option : $census->root();
        $root = $census->packages()[$package] ?? null;

        if ($root === null) {
            $this->components->error("[{$package}] is not an installed family package (an application, or a vendor outside splicewire, rushing and schemastud). Run this in the package's own checkout, or name one with --package.");

            return self::FAILURE;
        }

        if ($package !== $census->root() && (str_contains($root.'/', '/vendor/') || ! file_exists($root.'/.git'))) {
            $this->components->error("[{$package}] is installed at {$root}, which is not its own git checkout. Its baseline is committed in its repository: link it, or run this there.");

            return self::FAILURE;
        }

        $scan = EnvPrefixCensus::readsOf($package, $root);

        if ($scan['unreadable'] !== []) {
            $this->components->error('These config files did not parse, so the baseline would be incomplete: '.implode(', ', $scan['unreadable']));

            return self::FAILURE;
        }

        $names = EnvPrefixCensus::unruled($scan['reads']);
        $existing = EnvPrefixCensus::baseline($root);

        // Deleting a committed baseline must not reopen the first-write capture: that would let a new name join.
        if ($existing === null && Process::path($root)->run(['git', 'ls-files', '--error-unmatch', EnvPrefixCensus::BASELINE])->successful()) {
            $this->components->error(EnvPrefixCensus::BASELINE." is tracked in {$package}'s repository but missing from its checkout. Restore it (git checkout -- ".EnvPrefixCensus::BASELINE.') before writing: only a package that never had a baseline captures one.');

            return self::FAILURE;
        }

        if ($existing !== null) {
            $joining = array_values(array_diff($names, $existing));

            if ($joining !== []) {
                $this->components->error(sprintf(
                    '%d name(s) would join the baseline, which only shrinks: %s. Name each new key with its config file\'s prefix (or, for a third party\'s own name, add it to EnvPrefixCensus::THIRD_PARTY in review).',
                    count($joining),
                    implode(', ', $joining),
                ));

                return self::FAILURE;
            }
        }

        $content = EnvPrefixCensus::renderBaseline($package, $names);
        $path = $root.'/'.EnvPrefixCensus::BASELINE;
        $leaving = $existing === null ? [] : array_values(array_diff($existing, $names));

        if (! $this->option('write')) {
            $this->line($content);
            $this->components->info(sprintf('%d name(s) for %s; %d would leave. Nothing written: pass --write.', count($names), EnvPrefixCensus::BASELINE, count($leaving)));

            return self::SUCCESS;
        }

        if (file_put_contents($path, $content) === false) {
            $this->components->error("Could not write {$path}.");

            return self::FAILURE;
        }

        $this->components->twoColumnDetail($package, sprintf('%s: %d name(s)%s', EnvPrefixCensus::BASELINE, count($names), $leaving !== [] ? ', removed '.implode(', ', $leaving) : ''));

        return self::SUCCESS;
    }
}
