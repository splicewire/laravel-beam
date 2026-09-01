<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Symfony\Component\Finder\Finder;

/**
 * Find code that hand-assembles a polymorphic `*_type` value instead of asking the morph map.
 *
 * ## The defect this exists for, measured 2026-09-01
 * `tenant_sync_lineages.syncable_type` held **5,871 rows across 18 tenant schemas, 100% fully-qualified
 * class names**. One line did it: `TenantSyncTarget` wrote `'syncable_type' => $class` from a raw
 * class-string parameter, and six readers queried `where('syncable_type', Foo::class)`. Nothing on that
 * path ever called `getMorphClass()`, so the morph map was bypassed entirely.
 *
 * That silently defeated `determination-rename` issue 09 — closed, and aimed at exactly this: *"a package
 * declares a short stable morph key for each of its models, so the database stores that key instead of a
 * fully-qualified class name. A namespace rename then touches zero rows, because no row ever held a class
 * name."* The aliases WERE declared. The rows went on holding class names, because the declaration had no
 * consumer on that path.
 *
 * ## Why {@see MorphAliasCoverageAudit} cannot see this, which is the whole reason for a second audit
 * That audit asks *"does this model have an alias?"* and reports the alias as present — **which it is**.
 * It has no view of whether any writer ASKS for it. The two are complementary: one checks the
 * declaration exists, this one checks the callers use it. Neither substitutes for the other, and the
 * instrument that actually caught the 5,871 rows was a census of stored values, which is a third thing
 * again and cannot run in a package suite.
 *
 * ## Two checks, and the weaker one is the one that matters
 * - `morph-token.class-literal` — a `::class` reaching a `*_type` column. Unambiguously wrong: a
 *   class-string is not a morph token.
 * - `morph-token.raw-value` — a bare variable reaching one. **Ambiguous by construction** — that
 *   variable may already hold a morph key — so it is a nomination, not a proof, and says so. It is
 *   listed anyway because the defect that prompted this audit was exactly this shape, and an audit that
 *   only caught `::class` would have reported the estate clean while 5,871 wrong rows sat there.
 *
 * Correct callers are excluded by construction: a value mentioning `getMorphClass()` or `morphKeyFor()`
 * is the fix, not the defect.
 *
 * ## Advisory, permanently
 * Reach is `vendor/composer/installed.json` (see {@see FamilyPackageSource}) plus the host's own `app/`,
 * so the population is WHAT THIS HOST COMPOSES — a host fact, which by the estate's standing rule is a
 * finding and never a throw. A host that wants it to block registers it in its own manifest with
 * `gate: true` and runs `--floor=warn`.
 */
class MorphTokenBypassAudit implements DoctorAudit
{
    /**
     * A value expression containing any of these is asking the morph map correctly, not bypassing it.
     */
    protected const CORRECT_CALLS = ['getMorphClass', 'morphKeyFor', 'getActualClassNameForMorph'];

    /** @return list<Finding> */
    public function run(): array
    {
        $findings = [];
        $scanned = 0;

        foreach ($this->scanRoots() as $label => $dirs) {
            foreach ($dirs as $dir) {
                if (! is_dir($dir)) {
                    continue;
                }

                foreach ($this->phpFilesIn($dir) as $path => $source) {
                    $scanned++;

                    foreach ($this->hitsIn($source) as $hit) {
                        $findings[] = Finding::warn($hit['check'], sprintf(
                            '%s: %s passes %s into the `%s` column. %s',
                            $label,
                            $this->relative($path),
                            $hit['value'],
                            $hit['column'],
                            $hit['check'] === 'morph-token.class-literal'
                                ? 'A class-string is not a morph token — the map is being bypassed, so the '
                                    .'FQCN lands in the row and a rename breaks every one of them. Ask for it: '
                                    .'`(new Foo)->getMorphClass()`, or a model-side helper that does.'
                                : 'If that variable holds a class-string rather than a morph key, the FQCN '
                                    .'lands in the row. Ambiguous from source alone — confirm it, or route it '
                                    .'through `getMorphClass()` so it cannot be either.'
                        ));
                    }
                }
            }
        }

        // ⚠️ An empty population is "nothing here", not "measured clean" — the trap DoctorAudit's own
        // docblock names. This audit's population is `vendor/composer/installed.json` plus the host's
        // `app/`, so run from a package testbench it scans NOTHING and would otherwise report a
        // confident Pass over zero files. Say so instead.
        if ($scanned === 0) {
            return [Finding::inconclusive(
                'morph-token.bypass',
                'No source was scanned: this host composes no family packages and has no app/ directory, '
                .'so there was no population to measure. Run this from a composed host.'
            )];
        }

        if ($findings === []) {
            return [Finding::pass(
                'morph-token.bypass',
                sprintf('Every polymorphic `*_type` value across %d scanned file(s) of installed family '
                    .'source and host app code is asked of the morph map rather than spelled by hand.', $scanned)
            )];
        }

        return $findings;
    }

    /**
     * @return list<array{check: string, column: string, value: string}>
     */
    public function hitsIn(string $source): array
    {
        $out = [];

        // Both shapes that reach a morph column: an array-literal write (`'x_type' => V`) and a query
        // (`where('x_type', V)`). A `.`-qualified column (`sib.siloable_type`) counts — a raw join is
        // exactly where a hand-spelled token hides.
        //
        // ⚠️ `::` as well as `->`. The six readers that produced the 5,871-row defect were all STATIC
        // `Model::where('syncable_type', Foo::class)`, which is the commonest Laravel form — an
        // arrow-only pattern reports those files clean, which is how this audit would have missed the
        // exact defect it was written for. Caught by its own test, not by review.
        $patterns = [
            '/[\'"]([\w.]*_type)[\'"]\s*=>\s*([^,\)\]]+)/',
            '/(?:->|::)\s*(?:or)?[wW]here\w*\(\s*[\'"]([\w.]*_type)[\'"]\s*,\s*(?:[\'"][^\'"]*[\'"]\s*,\s*)?([^,\)]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $m) {
                $column = $m[1];
                $value = trim($m[2]);

                if ($this->isCorrect($value) || $this->isLiteralToken($value)) {
                    continue;
                }

                if (str_contains($value, '::class')) {
                    $out[] = ['check' => 'morph-token.class-literal', 'column' => $column, 'value' => $value];
                } elseif (preg_match('/^\$[A-Za-z_]\w*(->\w+)*$/', $value)) {
                    $out[] = ['check' => 'morph-token.raw-value', 'column' => $column, 'value' => $value];
                }
            }
        }

        return $out;
    }

    protected function isCorrect(string $value): bool
    {
        foreach (static::CORRECT_CALLS as $call) {
            if (str_contains($value, $call)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A quoted string is a morph key spelled inline. Not this audit's finding: it is already a token,
     * not a class. (It is coupled to the alias never changing, which is a style point, not a defect —
     * and flagging it would bury the two checks that are.)
     */
    protected function isLiteralToken(string $value): bool
    {
        return (bool) preg_match('/^[\'"]/', $value);
    }

    /** @return array<string, list<string>> */
    protected function scanRoots(): array
    {
        $roots = (new FamilyPackageSource)->dirs();

        // The host's own code can hand-assemble a morph value exactly as a package can, and nothing else
        // audits it — the flagship is where `PermissionsSeeder` and the app models live.
        if (is_dir($app = base_path('app'))) {
            $roots['(host app)'] = [$app];
        }

        return $roots;
    }

    /** @return array<string, string> path => source */
    protected function phpFilesIn(string $dir): array
    {
        $out = [];

        foreach ((new Finder)->files()->in($dir)->name('*.php') as $file) {
            $out[$file->getRealPath()] = (string) file_get_contents($file->getRealPath());
        }

        return $out;
    }

    protected function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
