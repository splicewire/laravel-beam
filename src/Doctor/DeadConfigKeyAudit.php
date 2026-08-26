<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Support\Facades\Config;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\Support\ConfigKeyScanner;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;

/**
 * **A `config()` read whose root nothing loaded is dead** — it resolves to null, silently, forever.
 *
 * The estate has now produced this bug four times, in four packages, and not one of them was caught by
 * a test:
 *
 *  - `laravel-beam-commerce/routes/webhook.php` read `beam-commerce.webhook.path` after the package
 *    moved to `config/beam/commerce.php`. `COMMERCE_WEBHOOK_PATH` was **dead in production**: a host
 *    relocating its Stripe webhook was silently ignored and its endpoint 404'd at the configured URL.
 *    The file's neighbour promises "the path root is config-driven … so a satellite can re-root it"
 *    directly above the line that could not.
 *  - `laravel-beam-mdx`'s whole suite seeded `beam-mdx.content_path`, so six tests asserted against the
 *    package default and the draft/gated visibility rules were **uncovered**, not merely red.
 *  - `laravel-beam-workflows`' BootTest asserted `beam-workflows.*` against null.
 *  - and the same package's `WebhookPathOverrideTest` — a *regression test for this exact bug*, written
 *    the first time it happened — kept passing through the second occurrence, because it set the same
 *    wrong key the broken route read.
 *  - `laravel-beam-taxonomy`'s suite seeded `beam-taxonomy.models.tag` while the provider read
 *    `beam.taxonomy.models.tag` (api-surface-coherence 53). This one is the write half ALONE: the
 *    production read was correct, so nothing here fired, and the seeding simply fell on the floor —
 *    three tests claiming to exercise the HOST binding asserted the package's own shipped default,
 *    and the no-host-model case could not be exercised at all.
 *
 * ## Writes count, and they are the more dangerous half
 * That fifth specimen is why `config()->set` / `$app['config']->set` are scanned alongside the reads,
 * having previously been excluded as "a test idiom". A dead read fails visibly at runtime; a dead
 * write turns an absent assertion into a passing one, silently, and there is nothing downstream to
 * notice.
 *
 * That last one is the argument for a mechanical check rather than a convention: **a test pinned to the
 * same key as the code under test validates nothing**, and no amount of review reliably notices two
 * halves of one file agreeing with each other about a key the config never had.
 *
 * ## The predicate is runtime truth, not a naming rule
 * The check does not encode "keys must be nested." It asks the config repository what roots are
 * actually loaded and reports every literal read outside that set. A dead read is dead whatever the
 * convention says, and a conformant read is never flagged — so the audit needs no list to maintain and
 * cannot go stale as packages are added.
 *
 * Two severities, because two different things are wrong:
 *  - **Fail — the abandoned flat twin.** The root is absent AND its dotted spelling IS loaded
 *    (`beam-commerce` → `beam.commerce`). This is the estate's recurring bug, the fix is mechanical, and
 *    the reader is provably wrong rather than merely optimistic.
 *  - **Warn — an unloaded root.** Everything else. A package legitimately reading an optional sibling's
 *    config in a host that has not installed it lands here, which is why it is not a failure.
 *
 * ## `tests/` is IN scope, unlike the facade-conformance regime
 * {@see FacadeConformanceScope} excludes tests by jurisdiction — it governs production drift, and a test
 * resolving a writer by hand differs only cosmetically. The opposite holds here: three of the four
 * specimens above were *in* tests, and a test reading a dead key is the more dangerous half of the bug,
 * because it converts an absent assertion into a passing one.
 */
class DeadConfigKeyAudit implements DoctorAudit
{
    public const CHECK = 'config.dead-key';

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $loaded = array_keys((array) Config::all());
        $dead = [];

        foreach (ConfigKeyScanner::rootsIn($this->files()) as $root => $sites) {
            if (in_array($root, $loaded, true)) {
                continue;
            }

            $dead[$root] = $sites;
        }

        if ($dead === []) {
            return [Finding::pass(self::CHECK, 'Every literal config() read and write resolves to a loaded root.')];
        }

        $findings = [];

        foreach ($dead as $root => $sites) {
            $findings[] = $this->finding($root, $sites, $loaded);
        }

        return $findings;
    }

    /**
     * One finding per dead root, naming up to three call sites. Per ROOT rather than per site because
     * the fix is per root — one rename repairs every reader of it, and forty findings for one typo is
     * how an audit gets its floor bumped and then ignored.
     *
     * @param  list<string>  $sites
     * @param  list<string>  $loaded
     */
    protected function finding(string $root, array $sites, array $loaded): Finding
    {
        $shown = implode(', ', array_slice($sites, 0, 3));
        $rest = count($sites) - min(3, count($sites));
        $where = $shown.($rest > 0 ? " (+{$rest} more)" : '');

        $nested = str_replace('-', '.', $root);

        // The abandoned flat twin: the dotted spelling of this root IS loaded, so the reader is not
        // optimistic about an absent package — it is simply on the wrong side of a rename.
        if ($nested !== $root && Config::has($nested)) {
            return Finding::fail(self::CHECK, sprintf(
                '`%s.*` is read or written in %d place(s) but no such config root is loaded — `%s` is. The '.
                'reads resolve to null and fall through to whatever inline default sits beside them, and '.
                'the writes land on a root nothing reads, so every env var, host override and test '.
                'seeding behind them is dead. Rename to `%s.*`: %s',
                $root,
                count($sites),
                $nested,
                $nested,
                $where,
            ));
        }

        unset($loaded);

        return Finding::warn(self::CHECK, sprintf(
            '`%s.*` is read or written in %d place(s) and no config root by that name is loaded here, so '.
            'the reads resolve to null and the writes land where nothing reads. Expected if the owning '.
            'package is optional and uninstalled; a defect otherwise: %s',
            $root,
            count($sites),
            $where,
        ));
    }

    /**
     * The scanned population: the host's own authored code plus every family package it composes through
     * the overlay — the same resolution-mode reasoning {@see FacadeConformanceScope} established, because
     * a pinned git-resolved `vendor/` copy is immutable and its keys are correct for the version it
     * pinned. `tests/` is deliberately included here (see the class docblock).
     *
     * @return list<string>
     */
    protected function files(): array
    {
        $roots = array_values(array_filter([
            base_path('app'),
            base_path('src'),
            base_path('routes'),
            base_path('database'),
            ...FacadeConformanceScope::authorablePackageRoots(),
        ], 'is_dir'));

        return ConfigKeyScanner::filesIn($roots);
    }
}
