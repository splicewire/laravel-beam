<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Surgeon\Support\HostScanRoots;

/**
 * The **docblock-vs-declaration** audit: does the sentence written on top of a declaration still agree
 * with the declaration underneath it?
 *
 * ## The gap this fills, stated precisely
 *
 * The estate's audits police whether declarations agree with EACH OTHER across a seam — a route against a
 * registry, a Data class against a migration, an SDK request against a route name. Measured 2026-08-29:
 * 93 audits run in one `surgeon:audit` sweep at the flagship and **none** of them compares a declaration
 * against its own docblock. That sentence is the first thing every author reads, because it is the only
 * part of the file written for a human — so when it drifts, it does not merely mislead a reader, it
 * becomes the premise of the next ticket.
 *
 * The calibration case is `Schemastud\Frame\Registry\RouteContextEntry`, and it carries both defects this
 * audit exists to find, in one 49-line file:
 *
 *  1. its constructor docblock directs the reader to `$widget/$redirect` — and **there is no `$redirect`
 *     parameter**, nor has there ever been one;
 *  2. it declares `public string $mounts = 'list'` — an unconstrained string — while the TypeScript the
 *     frontend compiles against declares the closed union `'list'|'edit'|'detail'|'widget'|'redirect'`.
 *     The backend can emit a value the frontend's own type forbids, and nothing between them says so.
 *
 * A careful regrounding session read that docblock, transcribed it faithfully, and shipped three wrong
 * structural claims off it. This audit is the mechanical answer to that class of authoring defect.
 *
 * ## Three checks, two severities, and why the split falls where it does
 *
 * `rushing/laravel-doctor`'s gate-or-advisory rule is that a check may throw only on what the
 * declaration's own author could have gotten right **without knowing which host would load it**.
 *
 *  - {@see CHECK_PHANTOM} is a **Fail**. Whether a docblock names a parameter that the constructor
 *    beneath it declares is decidable from the one file, needs no host, no boot, and no config. It is
 *    grammar, and this is the textbook legitimate fatal.
 *  - {@see CHECK_TS_NARROWER} and {@see CHECK_ENUM} are **Warn**. Both need a counterpart artifact whose
 *    presence is a fact about where the audit is standing — a TypeScript root that a package repo does
 *    not have, an enum declared in a package this host did not install — so a missing counterpart must
 *    read as *not checked*, never as *checked and clean*.
 *
 * ## Honesty about reach — every check states its own population
 *
 * This audit's whole subject is instruments that report success by not running, so it must not be one.
 * Every run emits a leading {@see Finding::pass()} census naming how many files were parsed, how many
 * classes carry `#[TypeScript]`, how many TypeScript roots resolved, and how many of those classes found
 * a counterpart declaration. A zero in that line is legible as a zero; a silent empty report is not.
 *
 * Three limits, recorded rather than left to be rediscovered:
 *
 *  - **{@see CHECK_TS_NARROWER} compares against hand-written TypeScript, not generated output.** The
 *    calibration case's counterpart lives in the JS sibling package (`js/packages/schemastud/frame`), is
 *    authored by hand, and no `#[TypeScript]` transformer emits it anywhere in this estate — measured
 *    2026-08-29, `RouteMounts` occurs in exactly one source file fleet-wide. A comparison against
 *    *emitted* TypeScript would have found nothing here, which is why the counterpart is resolved by
 *    search rather than assumed to be generated.
 *  - **A PHP type is only ever reported as WIDER, never as different.** `?string` against
 *    `string | null` is agreement; `string` against a closed union of string literals is the defect.
 *    Anything the comparator cannot place is skipped, so a false Fail cannot be manufactured out of a
 *    type shape nobody anticipated.
 *  - **{@see CHECK_ENUM} resolves an enum only within the scanned file set.** An enum this run never read
 *    contributes nothing, and the census line says how many were unresolvable.
 *
 * ## The estate-wide reading, and what its shape means
 *
 * Measured 2026-08-29 at `~/Herd/splicewire-app`: **4,969 PHP files, 4,760 class-likes, 275 carrying
 * `#[TypeScript]`** — and **two findings, both of them `RouteContextEntry`**. That is the acceptance case
 * and nothing else, which is a claim worth reading carefully rather than as a clean bill of health:
 *
 *  - {@see CHECK_PHANTOM}'s first draft returned **1,098**. Scoping it to constructor `@param` lines cut
 *    it to 38, and excluding {@see NOT_A_PARAMETER_REFERENCE} cut it to 1. Every one of the 1,097 removed
 *    was checked by hand and was a false positive — a class docblock's code example, a JSON Schema
 *    keyword in backticks, or a `Closure(mixed $model, mixed $actor)` type naming the closure's own
 *    parameters. The narrow check is the honest one, and its finding is real.
 *  - {@see CHECK_TS_NARROWER} is doing very little work today, and the census says why: of 275
 *    `#[TypeScript]` classes, **3** found a counterpart declaration, across **2** resolved TypeScript
 *    roots. Almost every family PHP package has no JS sibling, so almost every `#[TypeScript]` class has
 *    nothing to be compared against. Widening this means declaring more roots, not changing the check —
 *    and a host that wants the comparison must say where its TypeScript lives.
 *  - {@see CHECK_ENUM} reports **zero live instances estate-wide**. It is retained because the shape is
 *    real and its one candidate was a hand-verified false positive that the recognition guard now
 *    removes; but nobody should read a zero here as evidence the estate has no docblock enumerations —
 *    read it as "no docblock enumerates a resolvable enum and gets one of its cases wrong, today".
 *
 * Advisory as a whole in `BeamServiceProvider`, because the POPULATION is a host fact (which packages
 * this host composes) even where an individual row's verdict is a fact about one declaration.
 */
class DeclarationDocblockAudit implements DoctorAudit
{
    /** A docblock naming a `$parameter` the declaration beneath it does not declare. Fail — see the class docblock. */
    public const CHECK_PHANTOM = 'docblock.phantom-parameter';

    /** A PHP property type wider than the TypeScript declaration the frontend compiles against. Warn. */
    public const CHECK_TS_NARROWER = 'docblock.wider-than-typescript';

    /** A docblock enumerating cases that the property's own backed enum does not carry. Warn. */
    public const CHECK_ENUM = 'docblock.enumeration-disagrees';

    /** The census line — always emitted, so an empty report is distinguishable from an unread one. */
    public const CHECK_CENSUS = 'docblock.census';

    /**
     * Docblock variables that never denote a declared parameter and so can never be phantom: `$this` is
     * the receiver, and the two loop idioms below appear in `@param` examples across the estate.
     *
     * @var list<string>
     */
    public const NOT_PARAMETERS = ['this', 'key', 'value'];

    /**
     * Docblock spans a `$name` inside is NOT a reference to a declared parameter, stripped before the
     * phantom scan: an inline code span, a quoted string, and — the one that is easy to miss — the
     * parameter list of a `callable(...)` / `Closure(...)` TYPE, whose names belong to the callable and
     * have nothing to do with the declaration being documented. The last pattern is recursive so a nested
     * generic (`Closure(array<string, mixed> $filters): list<string>`) is consumed whole.
     *
     * @var list<string>
     */
    public const NOT_A_PARAMETER_REFERENCE = [
        '/`[^`]*`/',
        '/"[^"]*"/',
        "/'[^']*'/",
        '/\b(?:callable|Closure)\s*(\((?:[^()]++|(?1))*\))/i',
    ];

    /**
     * Directory names never descended into. `vendor` is skipped only as a NESTED dir — the resolved roots
     * are handed in directly, and every family package carries its own dev `vendor/` tree, so re-descending
     * re-scans the estate once per package.
     *
     * @var list<string>
     */
    public const SKIP_DIRS = ['vendor', 'node_modules', 'dist', '.git'];

    /**
     * @param  list<string>  $files  absolute paths of the PHP files to scan
     * @param  list<string>  $typescriptRoots  absolute dirs searched for a `#[TypeScript]` class's counterpart
     *                                         declaration; an empty list disables {@see CHECK_TS_NARROWER}
     *                                         and says so in the census
     */
    public function __construct(
        private array $files,
        private array $typescriptRoots = [],
    ) {}

    /**
     * The host-scoped construction: every host source dir plus one root per family package it composes,
     * resolved through the vendor symlink by {@see HostScanRoots} — the shared call site that exists
     * because `RecursiveDirectoryIterator` does not follow symlinks and three audits found that out
     * separately.
     *
     * TypeScript roots are DERIVED, never committed as literal paths: a host may declare extra roots in
     * `beam.surgeon.declaration_docblock.typescript_roots`, and each PHP package root additionally
     * contributes its structural JS sibling (`php/packages/<vendor>/laravel-<pkg>/src` ->
     * `js/packages/<vendor>/<pkg>/src`) when that directory exists. A committed artifact cannot carry
     * absolute per-machine paths, and a sibling that does not exist simply does not join the search.
     *
     * @param  list<string>|null  $roots  PHP scan roots, defaulting to the host's
     * @param  list<string>|null  $typescriptRoots  overrides the derivation entirely (used by tests)
     */
    public static function forApp(?array $roots = null, ?array $typescriptRoots = null): self
    {
        $roots = $roots ?? HostScanRoots::resolve();

        $files = [];
        foreach ($roots as $root) {
            foreach (self::filesUnder($root, ['php']) as $file) {
                $files[] = $file;
            }
        }

        return new self($files, $typescriptRoots ?? self::deriveTypescriptRoots($roots));
    }

    /**
     * The host's declared TypeScript roots plus each PHP root's structural JS sibling. Deduplicated by
     * resolved path; a root that does not resolve is dropped rather than searched.
     *
     * @param  list<string>  $phpRoots
     * @return list<string>
     */
    public static function deriveTypescriptRoots(array $phpRoots): array
    {
        $found = [];

        $declared = (array) config('beam.surgeon.declaration_docblock.typescript_roots', []);
        foreach ($declared as $root) {
            $path = str_starts_with((string) $root, '/') ? (string) $root : base_path((string) $root);
            if (is_string($resolved = realpath($path) ?: null)) {
                $found[$resolved] = true;
            }
        }

        foreach ($phpRoots as $root) {
            if (is_string($sibling = self::javascriptSibling($root))) {
                $found[$sibling] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * The JS sibling of one resolved PHP package root, or null. The estate's layout pairs
     * `~/Workspaces/php/packages/<vendor>/laravel-<pkg>` with `~/Workspaces/js/packages/<vendor>/<pkg>`;
     * the `laravel-` prefix is stripped because that is the PHP half's naming, not the package's identity.
     * Returns null for anything that does not match the shape or whose sibling is absent — the pairing is
     * a convention, so it is probed and never assumed.
     */
    public static function javascriptSibling(string $phpRoot): ?string
    {
        if (! preg_match('#^(.*)/php/packages/([^/]+)/([^/]+?)(?:/src)?$#', $phpRoot, $m)) {
            return null;
        }

        $name = str_starts_with($m[3], 'laravel-') ? substr($m[3], strlen('laravel-')) : $m[3];
        $sibling = $m[1].'/js/packages/'.$m[2].'/'.$name.'/src';

        return is_dir($sibling) ? (string) realpath($sibling) : null;
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $classes = [];
        foreach ($this->files as $file) {
            foreach (self::parseClasses($file) as $class) {
                $classes[] = $class;
            }
        }

        $typescript = $this->typescriptDeclarations();
        $enums = self::enumsIn($classes);

        $findings = [];
        $withCounterpart = 0;
        $unresolvableEnums = 0;

        foreach ($classes as $class) {
            foreach ($this->phantomFindings($class) as $finding) {
                $findings[] = $finding;
            }

            $short = self::shortName($class['name']);

            if ($class['typescript'] && isset($typescript[$short])) {
                $withCounterpart++;
                foreach ($this->widerThanTypescriptFindings($class, $typescript[$short]) as $finding) {
                    $findings[] = $finding;
                }
            }

            foreach ($this->enumerationFindings($class, $enums, $unresolvableEnums) as $finding) {
                $findings[] = $finding;
            }
        }

        array_unshift($findings, Finding::pass(self::CHECK_CENSUS, sprintf(
            '%d PHP file(s) parsed, %d class-like(s); %d carry #[TypeScript] and %d found a counterpart '
            .'declaration across %d TypeScript root(s)%s; %d docblock enum reference(s) were unresolvable '
            .'in this file set and were NOT checked.',
            count($this->files),
            count($classes),
            count(array_filter($classes, fn (array $c) => $c['typescript'])),
            $withCounterpart,
            count($this->typescriptRoots),
            $this->typescriptRoots === [] ? ' (none configured — the TypeScript check did not run)' : '',
            $unresolvableEnums,
        )));

        return $findings;
    }

    /* ---------------- check A — a docblock naming a parameter that does not exist ---------------- */

    /**
     * Every `$name` written in the class docblock or the constructor docblock that the class does not
     * declare as a constructor parameter or a property. This is the check that catches the calibration
     * case, and it is a Fail: the answer is a fact about one file and its own author.
     *
     * @param  array<string, mixed>  $class
     * @return list<Finding>
     */
    private function phantomFindings(array $class): array
    {
        $doc = $class['constructorDocblock'];
        if ($doc === null) {
            return [];
        }

        $declared = array_flip(array_merge($class['parameters'], $class['properties']));
        $findings = [];

        foreach (self::phantomsIn($doc['text'], $declared) as $variable) {
            $findings[] = Finding::fail(self::CHECK_PHANTOM, sprintf(
                '%s:%d — the constructor docblock of %s directs the reader to $%s on an @param line, and '
                .'%s declares no parameter or property by that name. Declared: %s.',
                self::relative($class['file']),
                $doc['line'],
                $class['name'],
                $variable,
                $class['name'],
                $declared === [] ? '(none)' : '$'.implode(', $', array_keys($declared)),
            ));
        }

        return $findings;
    }

    /**
     * The phantom `$names` on the `@param` lines of one docblock, in first-seen order.
     *
     * Three scoping decisions, each of which moved the estate-wide count by an order of magnitude when it
     * was measured on 2026-08-29 across 4,969 PHP files at the flagship:
     *
     *  - **`@param` lines only, and only inside the CONSTRUCTOR docblock.** A whole-docblock reader
     *    returned 1,098 findings, 1,060 of them from CLASS docblocks whose prose legitimately names
     *    variables from a code example (`$request`, `$app`, `$id`). Those are not claims about this
     *    declaration's parameters, and reporting them would have buried the 38 that are.
     *  - **{@see NOT_A_PARAMETER_REFERENCE} is stripped first.** A line reading *"the `$id` stem"* is
     *    talking about the JSON Schema keyword, not about a parameter — as are `$ref`, `$defs` and
     *    `$SCRIPTORIUM_CORPUS`. And a `Closure(mixed $model, mixed $actor): void` type names the
     *    parameters of the CLOSURE, not of the constructor: those two exclusions took the count from 38
     *    to 11, and every one of the 27 they removed was verified by hand to be a false positive.
     *  - **The whole line is read, not only the tag subject.** The strict reading — flag `@param $x` when
     *    the constructor has no `$x` — is genuinely zero-false-positive and would have found NOTHING in
     *    the calibration case, whose phantom sits in the *description* half of a well-formed
     *    `@param $mounts` line. The check that inspects only the tag is the check that misses the defect
     *    it was built for.
     *
     * @param  array<string, mixed>  $declared  a set keyed by declared parameter/property name
     * @return list<string>
     */
    public static function phantomsIn(string $docblock, array $declared): array
    {
        $phantoms = [];

        foreach (explode("\n", $docblock) as $line) {
            if (! str_contains($line, '@param')) {
                continue;
            }

            $line = (string) preg_replace(self::NOT_A_PARAMETER_REFERENCE, ' ', $line);

            foreach (self::variablesIn($line) as $variable) {
                if (isset($declared[$variable]) || in_array($variable, self::NOT_PARAMETERS, true) || in_array($variable, $phantoms, true)) {
                    continue;
                }

                $phantoms[] = $variable;
            }
        }

        return $phantoms;
    }

    /**
     * The `$name` tokens one docblock line mentions, deduplicated in first-seen order.
     *
     * @return list<string>
     */
    public static function variablesIn(string $docblock): array
    {
        if (! preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $docblock, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    /* ---------------- check B — a PHP type wider than the TypeScript the frontend trusts ---------------- */

    /**
     * Properties whose PHP type admits values the paired TypeScript declaration forbids. Only the
     * decidable direction is reported: a scalar PHP type against a TypeScript union of literals of that
     * same scalar. Everything else — a shape the comparator cannot place — is skipped rather than guessed
     * at, so this check cannot manufacture a finding out of an unanticipated type.
     *
     * @param  array<string, mixed>  $class
     * @param  array<string, mixed>  $counterpart
     * @return list<Finding>
     */
    private function widerThanTypescriptFindings(array $class, array $counterpart): array
    {
        $findings = [];

        foreach ($class['types'] as $property => $phpType) {
            if (! isset($counterpart['properties'][$property])) {
                continue;
            }

            $literals = self::literalUnion($counterpart['properties'][$property], $counterpart['aliases']);
            if ($literals === null || ! self::isWiderThanLiterals($phpType, $literals)) {
                continue;
            }

            $findings[] = Finding::warn(self::CHECK_TS_NARROWER, sprintf(
                '%s:%d — %s::$%s declares `%s`, but %s declares `%s` as the closed set %s. The backend '
                .'can emit a value the frontend\'s own declaration forbids, and nothing between them checks it.',
                self::relative($class['file']),
                $class['line'],
                $class['name'],
                $property,
                $phpType,
                self::relative($counterpart['file']),
                $property,
                implode(' | ', $literals),
            ));
        }

        return $findings;
    }

    /**
     * The literal members of a TypeScript type, resolving one level of `export type Alias = ...` from the
     * same file, or null when the type is not a union of literals of a single scalar kind. A union that
     * includes `null`, `undefined` or a bare `string` is NOT closed and returns null — those are exactly
     * the shapes a `?string` legitimately matches.
     *
     * @param  array<string, string>  $aliases
     * @return list<string>|null
     */
    public static function literalUnion(string $type, array $aliases): ?array
    {
        $type = trim($type);
        $type = $aliases[$type] ?? $type;

        $members = array_map('trim', explode('|', $type));
        if (count($members) < 2) {
            return null;
        }

        foreach ($members as $member) {
            if (! preg_match("/^'[^']*'$/", $member) && ! preg_match('/^-?\d+(\.\d+)?$/', $member) && $member !== 'true' && $member !== 'false') {
                return null;
            }
        }

        return $members;
    }

    /**
     * Is a PHP type strictly wider than a closed set of TypeScript literals of the same kind? Only the
     * unconstrained scalar spellings qualify — a nullable PHP type against a set with no null member is a
     * DIFFERENT disagreement (the frontend forbids null, the backend permits it), reported by nothing here
     * on purpose: it is common, usually intentional at a DTO boundary, and would drown the real signal.
     *
     * @param  list<string>  $literals
     */
    public static function isWiderThanLiterals(string $phpType, array $literals): bool
    {
        $stringly = str_starts_with($literals[0], "'");

        if ($stringly) {
            return $phpType === 'string';
        }

        if ($literals[0] === 'true' || $literals[0] === 'false') {
            return $phpType === 'bool';
        }

        return $phpType === 'int' || $phpType === 'float';
    }

    /**
     * Every `export interface X` / `export type X = { ... }` reachable from the configured TypeScript
     * roots, keyed by the short name a `#[TypeScript]` PHP class would emit under. A later root never
     * overwrites an earlier one — first declaration wins, and the census reports the root count so a
     * pairing that never happened is visible.
     *
     * @return array<string, array{file: string, properties: array<string, string>, aliases: array<string, string>}>
     */
    private function typescriptDeclarations(): array
    {
        $declarations = [];

        foreach ($this->typescriptRoots as $root) {
            foreach (self::filesUnder($root, ['ts', 'tsx']) as $file) {
                $code = (string) @file_get_contents($file);
                if ($code === '') {
                    continue;
                }

                $aliases = self::typeAliasesIn($code);

                foreach (self::interfacesIn($code) as $name => $properties) {
                    if (! isset($declarations[$name])) {
                        $declarations[$name] = ['file' => $file, 'properties' => $properties, 'aliases' => $aliases];
                    }
                }
            }
        }

        return $declarations;
    }

    /**
     * `export type Name = <single-line right-hand side>;` pairs from one TypeScript source. Single-line
     * only, deliberately: a multi-line alias is a shape, and the only aliases this audit resolves are the
     * flat unions {@see literalUnion()} can decide.
     *
     * @return array<string, string>
     */
    public static function typeAliasesIn(string $code): array
    {
        if (! preg_match_all('/^\s*export\s+type\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*([^;\n]+);/m', $code, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $aliases = [];
        foreach ($matches as $match) {
            $aliases[$match[1]] = trim($match[2]);
        }

        return $aliases;
    }

    /**
     * `export interface Name { ... }` bodies from one TypeScript source, each flattened to a
     * property-name => declared-type map. Comment lines are stripped before the property scan so a `//`
     * mentioning a type is never read as a declaration.
     *
     * @return array<string, array<string, string>>
     */
    public static function interfacesIn(string $code): array
    {
        if (! preg_match_all('/^\s*export\s+interface\s+([A-Za-z_][A-Za-z0-9_]*)[^{]*\{(.*?)^\s*\}/ms', $code, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $interfaces = [];
        foreach ($matches as $match) {
            $body = (string) preg_replace('#^\s*//.*$#m', '', $match[2]);
            $properties = [];

            if (preg_match_all('/^\s*([A-Za-z_][A-Za-z0-9_]*)\??\s*:\s*([^;\n]+);/m', $body, $rows, PREG_SET_ORDER)) {
                foreach ($rows as $row) {
                    $properties[$row[1]] = trim($row[2]);
                }
            }

            $interfaces[$match[1]] = $properties;
        }

        return $interfaces;
    }

    /* ---------------- check C — a docblock enumerating cases the enum does not carry ---------------- */

    /**
     * A property typed as a backed enum whose docblock enumerates backticked cases that the enum itself
     * does not declare. Scoped to enums declared inside the scanned file set: an enum this run never read
     * is counted into the census as unresolvable rather than guessed at, because "the enum is elsewhere"
     * and "the enum disagrees" must not produce the same output.
     *
     * @param  array<string, mixed>  $class
     * @param  array<string, list<string>>  $enums
     * @return list<Finding>
     */
    private function enumerationFindings(array $class, array $enums, int &$unresolvable): array
    {
        $doc = $class['constructorDocblock'] ?? $class['docblock'];
        if ($doc === null) {
            return [];
        }

        $findings = [];

        foreach ($class['types'] as $property => $phpType) {
            $short = self::shortName(ltrim($phpType, '?'));
            $enumerated = self::backtickedCasesFor($doc['text'], $property);

            if ($enumerated === []) {
                continue;
            }

            if (! isset($enums[$short])) {
                if (self::looksLikeClassName($short)) {
                    $unresolvable++;
                }

                continue;
            }

            $unknown = array_values(array_diff($enumerated, $enums[$short]));
            $recognised = array_intersect($enumerated, $enums[$short]);

            // The line must demonstrably be ENUMERATING this enum before a non-member counts as a
            // disagreement. Without this, any backticked token on the line is a candidate case, and the
            // first estate-wide run reported `.wasm` — a file extension on a line describing a `?Build`
            // property — as a case `Build` does not declare. A docblock that names none of the enum's own
            // cases is not enumerating it; it is describing something else in backticks.
            if ($unknown === [] || $recognised === []) {
                continue;
            }

            $findings[] = Finding::warn(self::CHECK_ENUM, sprintf(
                '%s:%d — the docblock for %s::$%s enumerates %s, which %s does not declare. Its cases are %s.',
                self::relative($class['file']),
                $doc['line'],
                $class['name'],
                $property,
                implode(', ', array_map(fn (string $c) => "`{$c}`", $unknown)),
                $short,
                implode(', ', $enums[$short]),
            ));
        }

        return $findings;
    }

    /**
     * The backticked tokens on the docblock line describing one property — `@param ... $name ...`. Scoped
     * to that one line so a neighbouring parameter's cases are never attributed here.
     *
     * @return list<string>
     */
    public static function backtickedCasesFor(string $docblock, string $property): array
    {
        foreach (explode("\n", $docblock) as $line) {
            if (! preg_match('/@param\b.*\$'.preg_quote($property, '/').'\b/', $line)) {
                continue;
            }

            if (preg_match_all('/`([a-z0-9_.\-]+)`/i', $line, $matches)) {
                return array_values(array_unique($matches[1]));
            }
        }

        return [];
    }

    /**
     * Backed-enum case VALUES by enum short name, from the scanned class set.
     *
     * @param  list<array<string, mixed>>  $classes
     * @return array<string, list<string>>
     */
    public static function enumsIn(array $classes): array
    {
        $enums = [];

        foreach ($classes as $class) {
            if ($class['kind'] !== 'enum') {
                continue;
            }

            $enums[self::shortName($class['name'])] = $class['cases'];
        }

        return $enums;
    }

    /** A token that could name a class — capitalized and not a PHP builtin scalar. */
    public static function looksLikeClassName(string $type): bool
    {
        return $type !== '' && $type[0] === strtoupper($type[0]) && ! in_array(strtolower($type), ['string', 'int', 'float', 'bool', 'array', 'mixed', 'object', 'iterable', 'callable', 'null', 'self', 'static'], true);
    }

    /* ---------------- the PHP reader ---------------- */

    /**
     * The class-likes one PHP file declares, each with its docblock, its constructor docblock, its
     * declared parameter and property names, its scalar property types, and — for an enum — its case
     * values.
     *
     * Read with `token_get_all()` rather than a parser dependency: this audit's whole subject is the
     * relationship between a doc comment and the tokens after it, and doc comments are exactly what an
     * AST discards by default. It also keeps the check free of a dependency laravel-beam does not
     * declare in its own `composer.json`.
     *
     * @return list<array<string, mixed>>
     */
    public static function parseClasses(string $file): array
    {
        $code = (string) @file_get_contents($file);
        if ($code === '' || ! str_contains($code, '<?php')) {
            return [];
        }

        $tokens = @token_get_all($code);
        $classes = [];

        $namespace = '';
        $pendingDoc = null;
        $pendingAttributes = '';

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::readName($tokens, $i);

                continue;
            }

            if ($token[0] === T_DOC_COMMENT) {
                $pendingDoc = ['text' => $token[1], 'line' => $token[2]];

                continue;
            }

            if ($token[0] === T_ATTRIBUTE) {
                $pendingAttributes .= self::readAttribute($tokens, $i);

                continue;
            }

            if (! in_array($token[0], [T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT], true)) {
                if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                    $pendingDoc = null;
                    $pendingAttributes = '';
                }

                continue;
            }

            // `Foo::class` and an anonymous `new class` are both T_CLASS and neither is a declaration.
            $next = self::nextMeaningful($tokens, $i);
            if ($next === null || ! is_array($tokens[$next]) || $tokens[$next][0] !== T_STRING) {
                $pendingDoc = null;
                $pendingAttributes = '';

                continue;
            }

            $classes[] = self::readBody($tokens, $next, [
                'file' => $file,
                'line' => $token[2],
                'kind' => $token[0] === T_ENUM ? 'enum' : 'class',
                'name' => ($namespace === '' ? '' : $namespace.'\\').$tokens[$next][1],
                'docblock' => $pendingDoc,
                'typescript' => str_contains($pendingAttributes, 'TypeScript'),
            ]);

            $pendingDoc = null;
            $pendingAttributes = '';
        }

        return $classes;
    }

    /**
     * Walk one class body from its name token, collecting the constructor's docblock and parameter names,
     * every property name, the scalar type of each, and an enum's case values. Depth-tracked so a nested
     * closure's `$variable` never reads as a property.
     *
     * @param  array<string, mixed>  $class
     * @return array<string, mixed>
     */
    private static function readBody(array $tokens, int $start, array $class): array
    {
        $class += ['parameters' => [], 'properties' => [], 'types' => [], 'cases' => [], 'constructorDocblock' => null];

        $braceDepth = 0;
        $entered = false;
        $parenDepth = 0;
        $pendingDoc = null;
        $type = null;

        // The function whose SIGNATURE we are inside, and the paren depth its parameter list opened at.
        // Without this, a method's own parameters read as class properties: they sit at class-brace depth
        // one, exactly where a property does, and only the enclosing parenthesis tells them apart.
        $signatureOf = null;
        $signatureParen = null;
        $pendingFunction = null;

        for ($i = $start; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                if ($token === '{') {
                    $braceDepth++;
                    $entered = true;
                    $type = null;
                } elseif ($token === '}') {
                    $braceDepth--;
                    $type = null;
                    if ($entered && $braceDepth === 0) {
                        break;
                    }
                } elseif ($token === '(') {
                    $parenDepth++;
                    if ($pendingFunction !== null && $signatureParen === null) {
                        $signatureOf = $pendingFunction;
                        $signatureParen = $parenDepth;
                        $pendingFunction = null;
                    }
                } elseif ($token === ')') {
                    if ($signatureParen !== null && $parenDepth === $signatureParen) {
                        $signatureOf = null;
                        $signatureParen = null;
                    }
                    $parenDepth--;
                    $type = null;
                } elseif ($token === '?') {
                    $type = '?';
                } else {
                    $type = null;
                }

                continue;
            }

            if ($token[0] === T_DOC_COMMENT) {
                $pendingDoc = ['text' => $token[1], 'line' => $token[2]];

                continue;
            }

            if ($token[0] === T_FUNCTION) {
                $next = self::nextMeaningful($tokens, $i);
                $pendingFunction = ($next !== null && is_array($tokens[$next])) ? strtolower($tokens[$next][1]) : '';

                if ($pendingFunction === '__construct') {
                    $class['constructorDocblock'] = $pendingDoc;
                }

                $pendingDoc = null;
                $type = null;

                continue;
            }

            if ($token[0] === T_CASE && $class['kind'] === 'enum' && $signatureParen === null) {
                $class['cases'][] = self::readCaseValue($tokens, $i);

                continue;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY], true)) {
                $type = ($type === '?' ? '?' : '').$token[1];

                continue;
            }

            if ($token[0] !== T_VARIABLE) {
                if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_READONLY], true)) {
                    $type = null;
                }

                continue;
            }

            $name = substr($token[1], 1);

            if ($signatureParen !== null && $parenDepth === $signatureParen) {
                // A parameter. Only the constructor's are the class's own declared surface — a promoted
                // property and a plain constructor argument are both things the class docblock may name.
                if ($signatureOf === '__construct') {
                    $class['parameters'][] = $name;
                    if (is_string($type) && $type !== '?') {
                        $class['types'][$name] = $type;
                    }
                }
            } elseif ($signatureParen === null && $braceDepth === 1) {
                $class['properties'][] = $name;
                if (is_string($type) && $type !== '?') {
                    $class['types'][$name] = $type;
                }
            }

            $type = null;
        }

        $class['parameters'] = array_values(array_unique($class['parameters']));
        $class['properties'] = array_values(array_unique($class['properties']));

        return $class;
    }

    /** The backed value of the enum case starting at `$i`, or its name when the enum is pure. */
    private static function readCaseValue(array $tokens, int $i): string
    {
        $name = '';

        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j] === ';') {
                break;
            }

            if (! is_array($tokens[$j])) {
                continue;
            }

            if ($tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                return trim($tokens[$j][1], "'\"");
            }

            if ($tokens[$j][0] === T_LNUMBER) {
                return $tokens[$j][1];
            }

            if ($tokens[$j][0] === T_STRING && $name === '') {
                $name = $tokens[$j][1];
            }
        }

        return $name;
    }

    /** The dotted name following a `namespace` keyword. */
    private static function readName(array $tokens, int $i): string
    {
        $name = '';

        for ($j = $i + 1; $j < count($tokens); $j++) {
            if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                break;
            }

            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $name .= $tokens[$j][1];
            }
        }

        return $name;
    }

    /** The raw source of one `#[...]` attribute group, brackets balanced. */
    private static function readAttribute(array $tokens, int &$i): string
    {
        $text = '';
        $depth = 1;

        for ($j = $i + 1; $j < count($tokens); $j++) {
            $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($piece === '[') {
                $depth++;
            }

            if ($piece === ']') {
                $depth--;
                if ($depth === 0) {
                    $i = $j;
                    break;
                }
            }

            $text .= $piece;
        }

        return $text;
    }

    /** The index of the next token that is not whitespace or a comment, or null at end of file. */
    private static function nextMeaningful(array $tokens, int $i): ?int
    {
        for ($j = $i + 1; $j < count($tokens); $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    /* ---------------- shared plumbing ---------------- */

    /**
     * Files with any of the given extensions under a directory, never descending into {@see SKIP_DIRS}.
     *
     * @param  list<string>|string  $extensions
     * @return list<string>
     */
    public static function filesUnder(string $dir, array|string $extensions): array
    {
        $extensions = (array) $extensions;

        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($extensions): bool {
                    if ($current->isDir()) {
                        return ! in_array($current->getFilename(), self::SKIP_DIRS, true);
                    }

                    return in_array($current->getExtension(), $extensions, true);
                },
            ),
        );

        foreach ($iterator as $file) {
            // An empty directory is yielded as a LEAF, so the extension filter never sees it.
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** The class short name of a possibly-qualified type. */
    public static function shortName(string $name): string
    {
        $pos = strrpos($name, '\\');

        return $pos === false ? $name : substr($name, $pos + 1);
    }

    /**
     * A path shortened for reading. Trimmed to the package/host root when one is recognizable, so a
     * finding stays legible without carrying a per-machine absolute path into anyone's notes.
     */
    private static function relative(string $file): string
    {
        foreach (['/php/packages/', '/js/packages/', '/Herd/'] as $marker) {
            $pos = strrpos($file, $marker);
            if ($pos !== false) {
                return substr($file, $pos + strlen($marker));
            }
        }

        return $file;
    }
}
