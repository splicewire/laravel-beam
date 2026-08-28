<?php

declare(strict_types=1);

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\Support\FamilyTailwindScan;

/**
 * ADVISORY, LIST-SHAPED: colour tokens a scanned family `dist` references that this host's `@theme`
 * never declares.
 *
 * The companion to {@see FamilySourceCoverageAudit}, and deliberately a TIER BELOW it. Coverage
 * answers "can Tailwind see the dist"; this answers "and does the class it found resolve to a
 * colour". Both roots that scored 100% coverage when this was written were shipping unstyled
 * navigation: `text-sidebar-active-foreground` was in the globbed dist, Tailwind saw it, and the
 * built stylesheet contained zero occurrences, because `--color-sidebar-active-foreground` is
 * declared nowhere. Shipping the coverage audit alone puts a green light over exactly that.
 *
 * ## Why this is a LIST and never a failure
 *
 * The coverage audit has the property that makes an audit worth adding: a resolved family `dist` with
 * no `@source` glob has **no legitimate reading**, so its clean state is genuinely zero. This audit
 * does **not** inherit that. "The dist references `--color-sidebar-ring` and the host does not define
 * it" has a perfectly good reading — *this host never renders that component*. Both findings known at
 * the time it was written were of exactly that shape at some root. So it emits {@see Finding::warn()}
 * with a work-list and a non-empty steady state is legitimate; it must never be read as a gate, and
 * it is registered with `gate: false` behind the coverage audit's order.
 *
 * ## It closes, which is the only reason it is checkable
 *
 * A referenced token is fine if the host declares `--color-<token>` in an `@theme` block, **or** if
 * it is a Tailwind BUILT-IN — and the built-in palette is a fixed, enumerable list. Without that
 * second half there is no way to tell `bg-red-500` from `bg-sidebar-ring`.
 *
 * ## Noise, and how it is kept out
 *
 * A first prototype ran ~50% noise: font sizes (`text-base`), SVG attributes (`stroke-width`), CSS
 * property names read out of dist stylesheets. Three filters, in order of how much each removes:
 * only JS-ish dist files are scanned (a package's own `dist/*.css` carries property names, not
 * utilities the host must generate); candidates are taken only from whole whitespace-delimited words
 * inside STRING LITERALS, which is where a class name lives in built output; and a per-prefix
 * exclusion list drops the non-colour utilities that share a colour prefix.
 *
 * ## Scope
 *
 * Only dists Tailwind actually scans are read — a package outside every `@source` glob cannot emit a
 * class at all, and reporting its tokens would double-count the coverage audit's finding. Everything
 * here is static file inspection; nothing builds.
 */
class FamilyTokenContractAudit implements DoctorAudit
{
    public const CHECK = 'family colour token contract';

    /** Tailwind v4's built-in palette families. Fixed and enumerable — the reason this check closes. */
    private const PALETTE = [
        'slate', 'gray', 'zinc', 'neutral', 'stone', 'red', 'orange', 'amber', 'yellow', 'lime',
        'green', 'emerald', 'teal', 'cyan', 'sky', 'blue', 'indigo', 'violet', 'purple', 'fuchsia',
        'pink', 'rose',
    ];

    /** Colourless built-in colour keywords. */
    private const KEYWORDS = ['inherit', 'current', 'transparent', 'black', 'white'];

    /** Utility prefixes that take a colour. `border-t` etc. are normalised onto `border`. */
    private const PREFIXES = [
        'bg', 'text', 'border', 'ring', 'outline', 'divide', 'from', 'via', 'to', 'fill', 'stroke',
        'accent', 'caret', 'decoration', 'shadow', 'placeholder',
    ];

    /** Directional/positional suffixes folded back onto their base prefix. */
    private const SIDES = ['t', 'r', 'b', 'l', 'x', 'y', 's', 'e', 'inline', 'block'];

    /**
     * Non-colour values that share a colour prefix. Measured against real dists, not guessed —
     * every entry here was an actual false positive.
     *
     * @var array<string, list<string>>
     */
    private const NOT_A_COLOUR = [
        '*' => [
            'none', 'auto', 'full', 'px', 'screen', 'min', 'max', 'fit', 'inherit', 'initial', 'unset',
            'reverse', 'inset', 'offset', 'opacity', 'width', 'height', 'size', 'style', 'color',
            'radius', 'box', 'image', 'position', 'repeat', 'attachment', 'clip', 'origin', 'spacing',
            'break', 'wrap', 'content', 'align', 'anchor', 'rendering', 'transform', 'overflow',
            'indent', 'family', 'variant', 'feature', 'weight', 'shadow', 'blend', 'top', 'bottom',
            'left', 'right', 'center', 'middle', 'start', 'end', 'baseline', 'solid', 'dashed',
            'dotted', 'double', 'hidden', 'visible', 'collapse', 'separate',
            // A bare side is a WIDTH utility, not a colour: `border-t`, `divide-y`, `border-x`. Without
            // this the audit reports `--color-t` / `--color-b` / `--color-y` on every root, which is
            // most of what a first prototype's noise actually was.
            't', 'r', 'b', 'l', 'x', 'y', 's', 'e', 'inline', 'block',
        ],
        'text' => ['xs', 'sm', 'base', 'lg', 'xl', 'justify', 'nowrap', 'balance', 'pretty', 'ellipsis', 'decoration', 'shadow'],
        'bg' => ['cover', 'contain', 'fixed', 'local', 'scroll', 'gradient', 'linear', 'radial', 'conic'],
        'border' => ['separate', 'collapse'],
        'ring' => [],
        'stroke' => ['linecap', 'linejoin', 'dasharray', 'dashoffset', 'miterlimit'],
        'fill' => ['rule'],
        'shadow' => ['xs', 'sm', 'md', 'lg', 'xl', 'inner'],
        'decoration' => ['wavy', 'slice', 'clone', 'from-font'],
        'divide' => [],
        'outline' => [],
    ];

    public function __construct(private readonly FamilyTailwindScan $scan) {}

    /** @return list<Finding> */
    public function run(): array
    {
        return [$this->contract()];
    }

    public function contract(): Finding
    {
        $entries = $this->scan->entries();

        if ($entries === []) {
            return Finding::pass(self::CHECK, 'no Tailwind v4 CSS entry found — this host is out of the audit\'s population.');
        }

        $declared = $this->declaredTokens($entries);
        $scanned = $this->scannedPackages();

        if ($scanned === []) {
            return Finding::pass(self::CHECK, 'no family dist is inside an @source glob — nothing this host would generate classes from.');
        }

        /** @var array<string, array<string, true>> $unknown token => packages */
        $unknown = [];

        foreach ($scanned as $package) {
            foreach ($this->referencedTokens($package['dist']) as $token) {
                if (isset($declared[$token]) || $this->isBuiltIn($token)) {
                    continue;
                }

                $unknown[$token][$package['name']] = true;
            }
        }

        if ($unknown === []) {
            return Finding::pass(
                self::CHECK,
                'every colour token referenced by '.count($scanned).' scanned family dist(s) is a Tailwind built-in or one of '.
                count($declared).' host @theme token(s).'
            );
        }

        ksort($unknown);

        $lines = [];
        foreach ($unknown as $token => $packages) {
            $names = array_keys($packages);
            sort($names);
            $lines[] = '  - --color-'.$token.'  (referenced by '.implode(', ', $names).')';
        }

        return Finding::warn(
            self::CHECK,
            count($unknown).' colour token(s) referenced by a scanned family dist are neither a Tailwind built-in nor declared in this '.
            'host\'s @theme, so any class using them emits nothing. A list, NOT a failure — a host that never renders the component is a '.
            'legitimate reading, and the fix is either an @theme declaration in '.$this->scan->rel($entries[0]).' or nothing:'.PHP_EOL.
            implode(PHP_EOL, $lines)
        );
    }

    /**
     * Every `--color-*` the host declares in an `@theme` block, FOLLOWING relative `@import`s — the
     * flagship's sidebar contract lives in `resources/css/brand-tokens.css`, not in the entry, and an
     * audit that stops at the entry reports the whole contract missing.
     *
     * `@theme` specifically, not `:root`: a custom property outside `@theme` is a value, not a
     * Tailwind namespace entry, and generates no utility.
     *
     * @param  list<string>  $entries
     * @return array<string, true>
     */
    private function declaredTokens(array $entries): array
    {
        $tokens = [];

        foreach ($entries as $entry) {
            foreach ($this->cssGraph($entry) as $contents) {
                foreach ($this->themeBlocks($contents) as $block) {
                    preg_match_all('/--color-([a-z0-9][a-z0-9-]*)\s*:/i', $block, $matches);

                    foreach ($matches[1] as $token) {
                        $tokens[strtolower($token)] = true;
                    }
                }
            }
        }

        return $tokens;
    }

    /**
     * The entry plus every RELATIVE `@import`ed stylesheet, transitively. Bare imports
     * (`tailwindcss`, `tw-animate-css`) are package imports, not host declarations.
     *
     * @return list<string>
     */
    private function cssGraph(string $entry, array &$seen = []): array
    {
        $entry = $this->scan->normalize($entry);

        if (isset($seen[$entry]) || count($seen) > 64) {
            return [];
        }

        $seen[$entry] = true;
        $contents = $this->scan->read($entry);

        if ($contents === null) {
            return [];
        }

        $graph = [$contents];

        preg_match_all('/@import\s+["\']([^"\']+)["\']/', $contents, $matches);

        foreach ($matches[1] as $import) {
            if (! str_starts_with($import, '.') && ! str_starts_with($import, '/')) {
                continue;
            }

            $path = str_starts_with($import, '/') ? $import : dirname($entry).'/'.$import;
            $path = $this->scan->normalize($path);

            foreach ([$path, $path.'.css'] as $candidate) {
                if (is_file($candidate)) {
                    $graph = array_merge($graph, $this->cssGraph($candidate, $seen));

                    break;
                }
            }
        }

        return $graph;
    }

    /**
     * The bodies of every `@theme` / `@theme inline` block, brace-matched — a regex to the first `}`
     * stops at the first nested rule and loses most of the contract.
     *
     * @return list<string>
     */
    private function themeBlocks(string $css): array
    {
        $blocks = [];
        $offset = 0;

        while (($position = strpos($css, '@theme', $offset)) !== false) {
            $open = strpos($css, '{', $position);

            if ($open === false) {
                break;
            }

            $depth = 0;
            $length = strlen($css);

            for ($i = $open; $i < $length; $i++) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $blocks[] = substr($css, $open + 1, $i - $open - 1);
                        break;
                    }
                }
            }

            $offset = $open + 1;
        }

        return $blocks;
    }

    /**
     * The family dists this host's globs (or its derivation plugin) actually put in front of Tailwind.
     *
     * @return list<array{name: string, dist: string, real: string}>
     */
    private function scannedPackages(): array
    {
        $packages = $this->scan->packages();

        if ($this->scan->pluginCarriers() !== []) {
            return $packages;
        }

        $globs = $this->scan->sources();

        return array_values(array_filter($packages, function (array $package) use ($globs): bool {
            foreach ($this->scan->distFiles($package['dist']) as $file) {
                if ($this->scan->matched($file, $globs)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Colour tokens referenced by a package's built output.
     *
     * JS-ish files only, and only whole words inside string literals — the two filters that took the
     * prototype's ~50% noise rate down. A dist stylesheet is skipped deliberately: it carries CSS
     * property names (`stroke-width`), which look exactly like a colour utility and are not one.
     *
     * ⚠️ Every literal form is NEWLINE-FREE on purpose, template literals included. Allowing a
     * backtick literal to span lines lets one stray backtick swallow hundreds of lines of built code
     * — comments included — and a code comment reading "a node mid text-edit isn't draggable" then
     * reports `--color-edit` as a missing token. Measured; it was the last false positive standing.
     *
     * @return list<string>
     */
    private function referencedTokens(string $dist): array
    {
        $tokens = [];

        foreach ($this->scan->distFiles($dist) as $file) {
            if (in_array(pathinfo($file, PATHINFO_EXTENSION), ['html', 'vue', 'svelte'], true)) {
                continue;
            }

            $contents = $this->scan->read($file);

            if ($contents === null) {
                continue;
            }

            preg_match_all('/"([^"\\\\\n]{0,4000})"|\'([^\'\\\\\n]{0,4000})\'|`([^`\\\\\n]{0,4000})`/', $contents, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $literal = $match[3] ?? '';
                $literal = $literal !== '' ? $literal : ($match[2] ?? '');
                $literal = $literal !== '' ? $literal : $match[1];

                $words = preg_split('/\s+/', trim($literal)) ?: [];

                // ⚠️ A literal holding ONE word is not read. `"text-delta"` is an event-name constant in
                // `@schemastud/chat`, indistinguishable from a class by any static rule, and it was the
                // last remaining false positive. Measured against the three findings this audit exists
                // to catch — `sidebar-ring`, `sidebar-accent-foreground`, `sidebar-active-foreground` —
                // every one of them occurs in a multi-word class list, so the filter costs nothing real.
                // It DOES mean a component whose only usage is `cn('bg-foo')` is invisible here; that is
                // a deliberate precision trade in a list-shaped advisory, not an oversight.
                if (count($words) < 2) {
                    continue;
                }

                foreach ($words as $word) {
                    $token = $this->colourToken($word);

                    if ($token !== null) {
                        $tokens[$token] = true;
                    }
                }
            }
        }

        return array_values(array_keys($tokens));
    }

    /**
     * `hover:!bg-sidebar-accent` -> `sidebar-accent`, or null when the word is not a colour utility.
     */
    private function colourToken(string $word): ?string
    {
        if ($word === '' || strlen($word) > 80) {
            return null;
        }

        // Strip variants (`dark:`, `group-hover:`, `md:`), the important/negative markers, and the
        // `/40` opacity modifier — `hover:bg-sidebar-accent/40` references the same token as
        // `bg-sidebar-accent`, and not stripping it drops half the real references on the floor.
        $position = strrpos($word, ':');
        $word = $position === false ? $word : substr($word, $position + 1);
        $word = ltrim($word, '!-');
        $slash = strpos($word, '/');
        $word = $slash === false ? $word : substr($word, 0, $slash);

        // Arbitrary values (`bg-[var(--x)]`) and CSS-variable shorthand are out of scope: the token is
        // not nameable statically, which is one of this audit's stated blind spots.
        if ($word === '' || ! preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/', $word)) {
            return null;
        }

        $parts = explode('-', $word);
        $prefix = array_shift($parts);

        if (! in_array($prefix, self::PREFIXES, true)) {
            return null;
        }

        // `border-t-sidebar` — fold the side back onto the prefix. Only when something follows it;
        // `border-t` alone is a width utility.
        if ($parts !== [] && count($parts) > 1 && in_array($parts[0], self::SIDES, true)) {
            array_shift($parts);
        }

        if ($parts === []) {
            return null;
        }

        $token = implode('-', $parts);

        if (in_array($parts[0], self::NOT_A_COLOUR['*'], true) || in_array($parts[0], self::NOT_A_COLOUR[$prefix] ?? [], true)) {
            return null;
        }

        if (in_array($token, self::NOT_A_COLOUR['*'], true) || in_array($token, self::NOT_A_COLOUR[$prefix] ?? [], true)) {
            return null;
        }

        // Numeric scales (`text-2xl`, `border-2`, `from-50%`, `shadow-2`) are never colours.
        if (preg_match('/^\d/', $parts[0]) === 1) {
            return null;
        }

        return $token;
    }

    private function isBuiltIn(string $token): bool
    {
        if (in_array($token, self::KEYWORDS, true)) {
            return true;
        }

        $parts = explode('-', $token);

        if (count($parts) !== 2 || ! in_array($parts[0], self::PALETTE, true)) {
            return false;
        }

        return preg_match('/^(50|100|200|300|400|500|600|700|800|900|950)$/', $parts[1]) === 1;
    }
}
