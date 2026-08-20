<?php

namespace Splicewire\Beam\Doctor\Support;

/**
 * Token-level reference detection for the two doctor-side facade-conformance audits (beam-facade ticket
 * 19) — {@see StubStaticReferenceAudit} and {@see ConfigFacadeReferenceAudit}. Text-level by substrate
 * (a `.php.stub` is invisible to surgeon, which hardcodes the `php` extension in
 * `AuditEngine::phpFilesIn()`), but **not** regex-level: the distinction the census turned on is
 * executable position versus prose, and `token_get_all()` draws that line exactly where PHP does.
 *
 * ## Why comment-awareness is load-bearing rather than a nicety
 * Ticket 07 ruled that **loose prose naming `Beam::table()` stays** — the call syntax never changed, so
 * a sentence about it is not stale — and swept only machine-resolved references (`{@see}`, `@method`,
 * `use`). Six `config/*.php` files across the estate mention `Beam::` and **all six are comments**;
 * `laravel-beam/config/beam/core.php:76` even carries a `{@see \Splicewire\Beam\Facades\Beam::table()}`
 * pointing at the correct class. A naive token match would report six violations where there are zero,
 * on day one, which is how an advisory check gets its floor bumped and then deleted (10 §5).
 *
 * So the scanner reports two separate channels and the audits choose:
 * - {@see codeReferences()} — the class named in **executable** position (an import, a static call, a
 *   type). Always drift when the class is the deleted bridge or the facade in a config file.
 * - {@see docTagReferences()} — the class named inside a **machine-resolved docblock tag** (`{@see}`,
 *   `@method`, `@param`, `@return`, `@var`). Drift only because the target no longer resolves; ordinary
 *   prose in the same comment is untouched.
 */
class FacadeReferenceScanner
{
    /** Docblock tags whose contents an IDE or a tool resolves to a real symbol — the ones the sweep moved. */
    public const RESOLVED_TAGS = ['@see', '@method', '@param', '@return', '@var', '@throws', '@uses'];

    /**
     * Line numbers where `$fqcn` is named in executable position. Comments and strings are structurally
     * invisible here — they are their own token types, and this only ever inspects name tokens.
     *
     * Exact-match by design: `Splicewire\Beam\Facades\Beam` must not answer to a search for
     * `Splicewire\Beam\Beam`, which is the entire ambiguity CONTEXT.md exists to end.
     *
     * @return list<int> sorted, deduplicated
     */
    public static function codeReferences(string $source, string $fqcn): array
    {
        $tokens = static::tokenize($source);

        if ($tokens === null) {
            return [];
        }

        $target = ltrim($fqcn, '\\');
        $aliases = static::aliasesFor($tokens, $target);
        $lines = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            // A qualified name — the import line itself, or an inline `\Splicewire\Beam\Facades\Beam::…`.
            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ltrim($token[1], '\\') === $target) {
                $lines[$token[2]] = true;

                continue;
            }

            // A short name resolved through the file's own imports — `Beam::table(…)` after a `use`. The
            // `::` requirement is what keeps an unrelated local class of the same short name out of it.
            if ($token[0] === T_STRING && isset($aliases[$token[1]]) && static::nextSignificant($tokens, $index) === '::') {
                $lines[$token[2]] = true;
            }
        }

        $lines = array_keys($lines);
        sort($lines);

        return $lines;
    }

    /**
     * The short names `$source` has bound to `$fqcn` via `use`, read from the file's own import map.
     * Public because a third check needs the same map for a different class: {@see SchemaCreateScanner}
     * resolves `Schema::create(…)` (beam-facade ticket 30) and `Schema` tokenizes as a bare `T_STRING`
     * exactly the way `Beam` does — 19 found a qualified-name scan finds every import and not one call
     * under it, and that finding is about the substrate, not about which class is being looked for.
     *
     * @return list<string> the short names, empty when the file never imports `$fqcn`
     */
    public static function importedShortNames(string $source, string $fqcn): array
    {
        $tokens = static::tokenize($source);

        if ($tokens === null) {
            return [];
        }

        return array_keys(static::aliasesFor($tokens, ltrim($fqcn, '\\')));
    }

    /**
     * The short names this file has bound to `$target` via `use` — usually one, `Beam`. Reading the
     * import map is what makes a call site visible: `Beam::table()` tokenizes as a bare `T_STRING`
     * carrying no namespace at all, so a scan for qualified names alone sees the import and misses every
     * call under it.
     *
     * A closure's `use ($captured)` is not an import; it is excluded by requiring a name token after the
     * keyword.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array<string, true>
     */
    private static function aliasesFor(array $tokens, string $target): array
    {
        $aliases = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                continue;
            }

            $name = null;
            $alias = null;

            for ($j = $i + 1; $j < $count; $j++) {
                $token = $tokens[$j];

                if ($token === ';' || $token === '{' || $token === '(') {
                    break;
                }

                if (! is_array($token) || $token[0] === T_WHITESPACE) {
                    continue;
                }

                if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                    // `use A\B\C as D` — the token after `as` is the alias, everything before is the name.
                    if ($name !== null && $alias === null) {
                        continue;
                    }

                    $name ??= ltrim($token[1], '\\');
                }

                if ($token[0] === T_AS && $j + 2 < $count && is_array($tokens[$j + 2])) {
                    $alias = $tokens[$j + 2][1];
                }
            }

            if ($name === $target) {
                $aliases[$alias ?? substr((string) strrchr('\\'.$name, '\\'), 1)] = true;
            }
        }

        return $aliases;
    }

    /**
     * The next non-whitespace, non-comment token's text after `$index`, or `null`.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextSignificant(array $tokens, int $index): ?string
    {
        $count = count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                return $token;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token[1];
        }

        return null;
    }

    /**
     * Line numbers where `$fqcn` appears inside a {@see RESOLVED_TAGS} tag in a comment or docblock.
     * The line reported is the comment's own start line — a docblock is one unit and splitting a finding
     * across its interior lines helps nobody.
     *
     * @return list<int> sorted, deduplicated
     */
    public static function docTagReferences(string $source, string $fqcn): array
    {
        $tokens = static::tokenize($source);

        if ($tokens === null) {
            return [];
        }

        $target = preg_quote(ltrim($fqcn, '\\'), '/');
        $tags = implode('|', array_map(static fn (string $tag): string => preg_quote($tag, '/'), static::RESOLVED_TAGS));
        // The negative lookahead keeps a tag naming `...\Facades\Beam` from answering to a search for
        // `...\Beam`: without it every correct {@see} on the facade reads as a reference to the bridge.
        $pattern = '/(?:'.$tags.')[^\n]*?\\\\?'.$target.'(?![A-Za-z0-9_\\\\])/';

        $lines = [];

        foreach ($tokens as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (preg_match($pattern, $token[1]) === 1) {
                $lines[$token[2]] = true;
            }
        }

        $lines = array_keys($lines);
        sort($lines);

        return $lines;
    }

    /**
     * Tokenize, or `null` when the source will not tokenize at all.
     *
     * This is where ticket 15's stub tolerance would have lived, and 18 §4 recorded that there is
     * nothing to build: the `.php.stub` population carries **no placeholder tokens whatsoever** — 39/39
     * `php -l` clean, matching 16's 62/62 on the dated population — so a stub is valid PHP verbatim and
     * parses like any other file. The tolerance survives as a posture rather than a special case: an
     * unparseable file yields **no findings**, never a syntax complaint. An advisory drift check has no
     * business reporting on a file's syntax, and a template dialect this regime has not met should go
     * quiet rather than noisy.
     *
     * @return list<array{0: int, 1: string, 2: int}|string>|null
     */
    private static function tokenize(string $source): ?array
    {
        try {
            return @token_get_all($source, TOKEN_PARSE);
        } catch (\Throwable) {
            return null;
        }
    }
}
