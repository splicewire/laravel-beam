<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Surgeon\Support\HostScanRoots;

/**
 * attention-surfaces 16 — the closed-vocabulary audit (decision 03, the expand step). A sibling to
 * {@see DeclarationDocblockAudit}: registered advisory the same way, scanning the same host-scoped roots
 * (a host's own source plus the family packages it composes), and emitting a census line on every run so
 * an empty report is distinguishable from an unread one.
 *
 * It reads the one thing {@see DeclarationDocblockAudit} never looks at — an attribute's ARGUMENT — for
 * two attributes, resolving both through the file's `use` imports and matching whole names:
 *
 *  - `#[LiteralTypeScriptType]` (Spatie TypeScript transformer), in any position — imported, aliased or
 *    fully qualified, first or later in an attribute group, on a property, a constructor parameter or a
 *    class. Its `$typeScript` argument (first positional or the `typeScript:` named argument) is the
 *    hand-written client type.
 *  - `#[In]` (Spatie laravel-data validation), on a Data class `string`/`?string` property, whose
 *    variadic argument is a validation vocabulary.
 *
 * It also reads a Data class `string` property that carries neither attribute but whose constructor
 * docblock types it as a union of quoted string literals.
 *
 * ## The five Warn checks
 *
 *  - {@see CHECK_VOCABULARY} — a `LiteralTypeScriptType` argument that is a union of two or more quoted
 *    string literals (with or without `null`), including one wrapped in `list<…>` / `(…)[]`. A closed
 *    vocabulary; it should be a backed enum.
 *  - {@see CHECK_INLINE_OBJECT} — a `LiteralTypeScriptType` argument that is an object with named members,
 *    or the array form of the attribute. A Data class, not a hand-typed shape.
 *  - {@see CHECK_DISCRIMINATOR_MISMATCH} — a single quoted literal whose property default is missing or a
 *    different string (the cheap half of ruling 4). A single literal is legitimate only as the arm a
 *    discriminated union is narrowed to, which is the property's own default.
 *  - {@see CHECK_DOCBLOCK_VOCABULARY} — a Data class `string` property with no type attribute whose
 *    constructor docblock is a literal union.
 *  - {@see CHECK_VALIDATION_VOCABULARY} — a Data class `string`/`?string` property carrying `#[In]` whose
 *    argument resolves to a fixed list of string literals (inline, varargs, or a class constant holding a
 *    literal array) and that has no enum type (attention-surfaces 26, ruled into 16). `#[In]` over enum
 *    cases, and method or dynamic arguments, are counted and never warned. The open-set escape is the
 *    convention's own: type the property to an interface — the check fires only on `string`/`?string`, so
 *    an interface-typed property is exempt by construction; a set left as a bare `string` (e.g.
 *    `Verticals::ALL`) cannot be told apart from a closed one and is warned until it is retyped.
 *
 * ## The census (always emitted)
 *
 * Every argument that is reported by nothing is counted into {@see CHECK_CENSUS}, which keeps each class
 * apart: a clean discriminator (single literal equal to its default), a version (numeric literal), a
 * structural type a PHPDoc type could express, an inference override the transpiler cannot place
 * (`unknown`, a `Record<…, unknown>`), an `#[In]` over enum cases, an `#[In]` with a dynamic argument,
 * and anything still unclassifiable (counted as `unclassified`, never dropped). The warned classes are
 * reported too, so one line describes the whole population.
 */
class ClosedVocabularyAudit implements DoctorAudit
{
    public const CHECK_VOCABULARY = 'typescript.literal-vocabulary';

    public const CHECK_INLINE_OBJECT = 'typescript.literal-inline-object';

    public const CHECK_DISCRIMINATOR_MISMATCH = 'typescript.literal-discriminator-mismatch';

    public const CHECK_DOCBLOCK_VOCABULARY = 'typescript.docblock-vocabulary';

    public const CHECK_VALIDATION_VOCABULARY = 'typescript.validation-vocabulary';

    public const CHECK_CENSUS = 'typescript.closed-vocabulary-census';

    public const LITERAL_FQN = 'Spatie\\TypeScriptTransformer\\Attributes\\LiteralTypeScriptType';

    public const TYPESCRIPT_FQN = 'Spatie\\TypeScriptTransformer\\Attributes\\TypeScript';

    public const IN_FQN = 'Spatie\\LaravelData\\Attributes\\Validation\\In';

    public const DATA_FQN = 'Spatie\\LaravelData\\Data';

    /**
     * @param  list<string>  $files  absolute paths of the PHP files to scan
     */
    public function __construct(
        private array $files,
    ) {}

    /**
     * Host-scoped construction: every host source dir plus one root per family package it composes,
     * resolved through the vendor symlink by {@see HostScanRoots} — the same roots the sibling audit scans.
     *
     * @param  list<string>|null  $roots  PHP scan roots, defaulting to the host's
     */
    public static function forApp(?array $roots = null): self
    {
        $roots ??= HostScanRoots::resolve();

        $files = [];
        foreach ($roots as $root) {
            foreach (DeclarationDocblockAudit::filesUnder($root, ['php']) as $file) {
                $files[$file] = true;
            }
        }

        return new self(array_keys($files));
    }

    /** @return list<Finding> */
    public function run(): array
    {
        if ($this->files === []) {
            return [Finding::inconclusive(
                self::CHECK_CENSUS,
                'No PHP file was scanned, so no closed-vocabulary conclusion can be drawn. The host resolved '
                .'no source roots (a missing vendor symlink, or an empty file set).'
            )];
        }

        $constants = $this->indexConstants();

        $findings = [];
        $census = array_fill_keys([
            'vocabulary', 'inline-object', 'discriminator-mismatch', 'docblock-vocabulary', 'validation-vocabulary',
            'discriminator', 'version', 'structural', 'inference-override', 'validation-enum', 'validation-dynamic', 'unclassified',
        ], 0);
        $seen = [];

        foreach ($this->files as $file) {
            foreach ($this->sitesIn($file, $constants) as $site) {
                // A site is identified by file + class + member + check-kind; a file scanned once cannot
                // double-count, and two attributes on one member are distinct kinds.
                $key = $file.'|'.$site['class'].'|'.$site['member'].'|'.$site['kind'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                [$finding, $class] = $this->classify($site, $file, $constants);
                $census[$class]++;
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        array_unshift($findings, Finding::pass(self::CHECK_CENSUS, sprintf(
            '%d PHP file(s) scanned, %d literal/validation site(s): '
            .'reported by nothing — %d discriminator, %d version, %d structural, %d inference-override, '
            .'%d in-enum, %d in-dynamic, %d unclassified; '
            .'warned — %d literal-vocabulary, %d inline-object, %d discriminator-mismatch, %d docblock-vocabulary, %d validation-vocabulary.',
            count($this->files),
            array_sum($census),
            $census['discriminator'], $census['version'], $census['structural'], $census['inference-override'],
            $census['validation-enum'], $census['validation-dynamic'], $census['unclassified'],
            $census['vocabulary'], $census['inline-object'], $census['discriminator-mismatch'],
            $census['docblock-vocabulary'], $census['validation-vocabulary'],
        )));

        return $findings;
    }

    /**
     * Classify one site into (Finding|null, census-class).
     *
     * @param  array<string, mixed>  $site
     * @param  array<string, string>  $constants
     * @return array{0: Finding|null, 1: string}
     */
    private function classify(array $site, string $file, array $constants): array
    {
        $where = sprintf('%s::%s', self::shortName($site['class']), $site['member']);
        $at = self::rel($file);

        if ($site['kind'] === 'docblock') {
            return [Finding::warn(self::CHECK_DOCBLOCK_VOCABULARY, sprintf(
                '%s (%s): a Data class `string` property has no #[LiteralTypeScriptType] and its constructor '
                .'docblock types it as the literal union %s — which emits `unknown`. Type it a backed enum '
                .'(closed-vocabulary.convention.md).',
                $where, $at, $this->shorten($site['argument'])
            )), 'docblock-vocabulary'];
        }

        if ($site['kind'] === 'validation') {
            return $this->classifyValidation($site, $where, $at, $constants);
        }

        // kind 'attribute' — a #[LiteralTypeScriptType] argument, already reduced to a value descriptor.
        $arg = $site['arg'];

        if ($arg['kind'] === 'array') {
            return [Finding::warn(self::CHECK_INLINE_OBJECT, sprintf(
                '%s (%s): the #[LiteralTypeScriptType] argument is given as an array, which is an object with '
                .'named members. An object is a Data class, never a hand-typed shape (closed-vocabulary.convention.md).',
                $where, $at
            )), 'inline-object'];
        }

        if ($arg['kind'] !== 'string') {
            return [null, 'unclassified']; // heredoc/concat/constant/unknown argument — counted, never dropped
        }

        return $this->classifyTypeString($arg['value'], $where, $at, $site, $constants);
    }

    /**
     * @param  array<string, mixed>  $site
     * @param  array<string, string>  $constants
     * @return array{0: Finding|null, 1: string}
     */
    private function classifyTypeString(string $value, string $where, string $at, array $site, array $constants): array
    {
        $s = trim($value);

        if ($this->isInlineObject($s)) {
            return [Finding::warn(self::CHECK_INLINE_OBJECT, sprintf(
                '%s (%s): the #[LiteralTypeScriptType] argument %s is an object with named members. An object '
                .'is a Data class, never a hand-typed shape (closed-vocabulary.convention.md).',
                $where, $at, $this->shorten($s)
            )), 'inline-object'];
        }

        if ($this->isLiteralUnion($s) || $this->isLiteralUnion($this->unwrapGenericOrArray($s))) {
            return [Finding::warn(self::CHECK_VOCABULARY, sprintf(
                '%s (%s): the #[LiteralTypeScriptType] argument %s is a closed set of strings. Type it a PHP '
                .'backed enum; the case values are the wire values (closed-vocabulary.convention.md).',
                $where, $at, $this->shorten($s)
            )), 'vocabulary'];
        }

        if (($literal = $this->singleStringLiteral($s)) !== null) {
            $default = $this->resolveDefault($site, $constants);
            if ($default === ['unresolved']) {
                return [null, 'unclassified']; // a constant default we cannot resolve — not compared as text
            }
            $defaultValue = $default[0] ?? null;
            if ($defaultValue === null || $defaultValue !== $literal) {
                return [Finding::warn(self::CHECK_DISCRIMINATOR_MISMATCH, sprintf(
                    '%s (%s): the single #[LiteralTypeScriptType] literal %s %s. A single literal is the arm a '
                    .'discriminated union is narrowed to, and must equal the property default.',
                    $where, $at, $this->shorten($s),
                    $defaultValue === null ? 'has no property default' : sprintf('does not equal the default %s', var_export($defaultValue, true))
                )), 'discriminator-mismatch'];
            }

            return [null, 'discriminator'];
        }

        if ($this->isNumericLiteral($s)) {
            return [null, 'version'];
        }

        if (preg_match('/\b(?:unknown|any|mixed)\b/', $s)) {
            return [null, 'inference-override']; // Record<string, unknown> and friends — F19
        }

        if ($this->isStructural($s)) {
            return [null, 'structural'];
        }

        return [null, 'inference-override'];
    }

    /**
     * Classify a `#[In]` validation site. Warn only when the property is `string`/`?string`, is not enum
     * typed, and the argument resolves to a fixed list of string literals.
     *
     * @param  array<string, mixed>  $site
     * @param  array<string, string>  $constants
     * @return array{0: Finding|null, 1: string}
     */
    private function classifyValidation(array $site, string $where, string $at, array $constants): array
    {
        if ($site['enumTyped']) {
            return [null, 'unclassified']; // property is enum typed already — outside the vocabulary question
        }
        if (! $site['stringTyped']) {
            return [null, 'unclassified']; // the convention's open-set escape: an interface-typed property is exempt
        }

        $kind = $this->resolveInValues($site['values'], $site['class'], $constants);

        if ($kind === 'string-list') {
            return [Finding::warn(self::CHECK_VALIDATION_VOCABULARY, sprintf(
                '%s (%s): the #[In] validation set on a `string` property is a fixed list of string literals '
                .'with no enum type. Type it a PHP backed enum and validate against its cases '
                .'(closed-vocabulary.convention.md).',
                $where, $at
            )), 'validation-vocabulary'];
        }
        if ($kind === 'enum') {
            return [null, 'validation-enum'];
        }
        if ($kind === 'dynamic') {
            return [null, 'validation-dynamic'];
        }

        return [null, 'unclassified'];
    }

    /* ---------------- classification of a TypeScript type string ---------------- */

    private function isInlineObject(string $arg): bool
    {
        // An object type with at least one named member: `{ foo: … }`, `{ readonly foo: … }`,
        // `{ 'foo-bar': … }`, `{ "x": … }`. An index signature (`{ [k: string]: T }`) is a record, not
        // named members, so the negative lookahead leaves it to the structural / inference classes.
        return (bool) preg_match('/\{\s*(?!\[)(?:readonly\s+)?(?:[A-Za-z_$][\w$]*|\'[^\']+\'|"[^"]+")\s*\??\s*:/', $arg);
    }

    private function unwrapGenericOrArray(string $s): string
    {
        $s = trim($s);
        foreach (['/^list<(.+)>$/is', '/^array<(.+)>$/is', '/^readonlyarray<(.+)>$/is'] as $re) {
            if (preg_match($re, $s, $m)) {
                return trim($m[1]);
            }
        }
        if (preg_match('/^\((.+)\)\[\]$/s', $s, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(.+)\[\]$/s', $s, $m)) {
            return trim($m[1]);
        }

        return $s;
    }

    private function isLiteralUnion(string $arg): bool
    {
        $parts = $this->unionParts($arg);
        if (count($parts) < 2) {
            return false;
        }

        $literals = 0;
        foreach ($parts as $part) {
            if ($this->isStringLiteral($part)) {
                $literals++;
            } elseif (strtolower($part) !== 'null') {
                return false;
            }
        }

        return $literals >= 2;
    }

    private function singleStringLiteral(string $arg): ?string
    {
        $parts = $this->unionParts($arg);
        $nonNull = array_values(array_filter($parts, fn (string $p) => strtolower($p) !== 'null'));

        if (count($nonNull) === 1 && $this->isStringLiteral($nonNull[0])) {
            return $this->unquoteTs($nonNull[0]);
        }

        return null;
    }

    private function isNumericLiteral(string $arg): bool
    {
        foreach ($this->unionParts($arg) as $part) {
            if (strtolower($part) === 'null') {
                continue;
            }
            if (! preg_match('/^-?\d+(\.\d+)?$/', $part)) {
                return false;
            }
        }

        return (bool) preg_match('/\d/', $arg);
    }

    private function isStructural(string $arg): bool
    {
        return (bool) preg_match('/<|\[\s*\]|\{/', $arg);
    }

    /** @return list<string> */
    private function unionParts(string $arg): array
    {
        $parts = [];
        $buf = '';
        $depth = 0;
        $quote = null;
        $len = strlen($arg);

        for ($i = 0; $i < $len; $i++) {
            $ch = $arg[$i];

            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === $quote && ($i === 0 || $arg[$i - 1] !== '\\')) {
                    $quote = null;
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $buf .= $ch;

                continue;
            }

            if ($ch === '{' || $ch === '<' || $ch === '[' || $ch === '(') {
                $depth++;
            } elseif ($ch === '}' || $ch === '>' || $ch === ']' || $ch === ')') {
                $depth--;
            }

            if ($ch === '|' && $depth === 0) {
                $parts[] = trim($buf);
                $buf = '';

                continue;
            }

            $buf .= $ch;
        }

        if (trim($buf) !== '') {
            $parts[] = trim($buf);
        }

        return $parts;
    }

    private function isStringLiteral(string $part): bool
    {
        return (bool) preg_match('/^([\'"]).*\1$/s', trim($part));
    }

    private function unquoteTs(string $part): string
    {
        $part = trim($part);

        return substr($part, 1, -1);
    }

    /* ---------------- token parsing ---------------- */

    /**
     * Every site in one file. kind 'attribute' carries `arg` (a value descriptor) and the declaration
     * default; kind 'validation' carries `values`/`stringTyped`/`enumTyped`; kind 'docblock' carries the
     * literal union. The attribute's name is resolved through the file's `use` imports and matched whole.
     *
     * @param  array<string, string>  $constants
     * @return list<array<string, mixed>>
     */
    public function sitesIn(string $file, array $constants = []): array
    {
        $code = @file_get_contents($file);
        if ($code === false) {
            return [];
        }
        if (! str_contains($code, 'LiteralTypeScriptType') && ! str_contains($code, 'TypeScript') && ! preg_match('/#\[\s*\\\\?[\w\\\\]*\bIn\b/', $code)) {
            return [];
        }

        $tokens = token_get_all($code);
        $uses = $this->resolveUses($tokens);

        $sites = [];
        $namespace = '';
        $classStack = [];
        $braceDepth = 0;
        $pending = [];        // parsed attributes (across consecutive groups) awaiting a declaration
        $pendingDoc = null;
        $ctorParen = null;    // paren-depth at which the current constructor's parameter list sits
        $parenDepth = 0;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];

            if (is_array($t)) {
                if ($t[0] === T_DOC_COMMENT) {
                    $pendingDoc = $t[1];

                    continue;
                }
                if (in_array($t[0], [T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }
                if ($t[0] === T_NAMESPACE) {
                    $namespace = $this->readName($tokens, $i);

                    continue;
                }
                if ($t[0] === T_ATTRIBUTE) {
                    foreach ($this->parseGroup($tokens, $i, $uses, $namespace) as $attr) {
                        $pending[] = $attr;
                    }
                    // $i now at the matching ]

                    continue;
                }
                if (in_array($t[0], [T_CLASS, T_ENUM, T_INTERFACE, T_TRAIT], true)) {
                    // `Foo::class` is not a declaration.
                    if ($this->prevSignificant($tokens, $i) === T_DOUBLE_COLON) {
                        continue;
                    }
                    $nameIdx = $this->nextSignificant($tokens, $i);
                    $name = ($nameIdx !== null && is_array($tokens[$nameIdx])) ? $tokens[$nameIdx][1] : '';
                    $fqn = ($namespace === '' ? '' : $namespace.'\\').$name;
                    $isTs = $this->pendingHasAttr($pending, self::TYPESCRIPT_FQN) || $this->extendsData($tokens, $i, $uses, $namespace);

                    foreach ($this->pendingAttrs($pending, self::LITERAL_FQN) as $attr) {
                        $sites[] = ['kind' => 'attribute', 'class' => $fqn, 'member' => '<class>', 'arg' => $attr['arg'], 'default' => null];
                    }

                    $classStack[] = ['name' => $fqn, 'ts' => $isTs, 'depth' => $braceDepth, 'ctorDoc' => null];
                    $pending = [];
                    $pendingDoc = null;

                    continue;
                }
                if ($t[0] === T_FUNCTION) {
                    $nameIdx = $this->nextSignificant($tokens, $i);
                    $fname = ($nameIdx !== null && is_array($tokens[$nameIdx])) ? $tokens[$nameIdx][1] : '';
                    if ($fname === '__construct' && $classStack !== []) {
                        $classStack[count($classStack) - 1]['ctorDoc'] = $pendingDoc;
                        $ctorParen = $parenDepth + 1; // its parameter list opens the next (
                    } else {
                        $ctorParen = null;
                    }
                    $pending = [];
                    $pendingDoc = null;

                    continue;
                }
                if ($t[0] === T_VARIABLE) {
                    $class = $classStack === [] ? null : $classStack[count($classStack) - 1];
                    $member = ltrim($t[1], '$');
                    $inCtorParams = $ctorParen !== null && $parenDepth === $ctorParen;
                    $typeInfo = $this->declaredTypeBefore($tokens, $i);
                    $default = $this->readDefault($tokens, $i);

                    $litAttr = $this->firstAttr($pending, self::LITERAL_FQN);
                    $inAttr = $this->firstAttr($pending, self::IN_FQN);

                    if ($litAttr !== null && $class !== null) {
                        $sites[] = ['kind' => 'attribute', 'class' => $class['name'], 'member' => $member, 'arg' => $litAttr['arg'], 'default' => $default];
                    } elseif ($inAttr !== null && $class !== null) {
                        $sites[] = [
                            'kind' => 'validation', 'class' => $class['name'], 'member' => $member,
                            'values' => $inAttr['values'],
                            'stringTyped' => $typeInfo['string'],
                            'enumTyped' => $typeInfo['name'] !== null && isset($constants['enum:'.self::shortName($typeInfo['name'])]),
                        ];
                    } elseif ($litAttr === null && $inAttr === null && $class !== null && $class['ts'] && $inCtorParams && $typeInfo['string']) {
                        $union = $this->docblockLiteralUnionFor($class['ctorDoc'], $member);
                        if ($union !== null) {
                            $sites[] = ['kind' => 'docblock', 'class' => $class['name'], 'member' => $member, 'argument' => $union];
                        }
                    }

                    $pending = [];
                    $pendingDoc = null;

                    continue;
                }
            } else {
                if ($t === '{') {
                    $braceDepth++;

                    continue;
                }
                if ($t === '}') {
                    $braceDepth--;
                    if ($classStack !== [] && $braceDepth === $classStack[count($classStack) - 1]['depth']) {
                        array_pop($classStack);
                    }

                    continue;
                }
                if ($t === '(') {
                    $parenDepth++;

                    continue;
                }
                if ($t === ')') {
                    if ($ctorParen !== null && $parenDepth === $ctorParen) {
                        $ctorParen = null; // left the constructor parameter list
                    }
                    $parenDepth--;

                    continue;
                }
                if ($t === ';') {
                    $pending = [];
                    $pendingDoc = null;

                    continue;
                }
            }
        }

        return $sites;
    }

    /* ---------------- use-import resolution ---------------- */

    /**
     * Map every imported short name (or alias) to its fully qualified name, including grouped
     * `use A\B\{C, D as E};`.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return array<string, string>
     */
    private function resolveUses(array $tokens): array
    {
        $uses = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (! (is_array($tokens[$i]) && $tokens[$i][0] === T_USE)) {
                continue;
            }
            // Read the whole use statement text up to ';'.
            $stmt = '';
            for ($j = $i + 1; $j < $count; $j++) {
                $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($piece === ';' || $piece === '{' && false) {
                    break;
                }
                if ($piece === ';') {
                    break;
                }
                $stmt .= $piece;
                if ($piece === '}') {
                    break;
                }
            }
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, 'function ') || str_starts_with($stmt, 'const ')) {
                continue;
            }

            if (preg_match('/^(.*)\{(.+)\}$/s', $stmt, $m)) {
                $prefix = trim($m[1]);
                foreach (explode(',', $m[2]) as $item) {
                    $this->recordUse($uses, $prefix.trim($item));
                }
            } else {
                $this->recordUse($uses, $stmt);
            }
        }

        return $uses;
    }

    /** @param  array<string, string>  $uses */
    private function recordUse(array &$uses, string $item): void
    {
        $item = trim($item);
        if ($item === '') {
            return;
        }
        if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $item, $m)) {
            $fqn = ltrim(trim($m[1]), '\\');
            $alias = trim($m[2]);
        } else {
            $fqn = ltrim($item, '\\');
            $alias = str_contains($fqn, '\\') ? substr($fqn, strrpos($fqn, '\\') + 1) : $fqn;
        }
        $uses[$alias] = $fqn;
    }

    /**
     * Resolve a written attribute/type name to a fully qualified name through the use map.
     *
     * @param  array<string, string>  $uses
     */
    private function resolveName(string $name, array $uses, string $namespace): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $first = str_contains($name, '\\') ? substr($name, 0, strpos($name, '\\')) : $name;
        if (isset($uses[$first])) {
            $rest = str_contains($name, '\\') ? substr($name, strpos($name, '\\')) : '';

            return $uses[$first].$rest;
        }

        return ($namespace === '' ? '' : $namespace.'\\').$name;
    }

    /* ---------------- attribute group parsing ---------------- */

    /**
     * Parse one `#[ … ]` group into its attributes, advancing $i to the matching `]`. Each attribute is
     * `{fqn, arg?, values?}`: `arg` for a LiteralTypeScriptType, `values` for an In.
     *
     * @param  array<string, string>  $uses
     * @return list<array<string, mixed>>
     */
    private function parseGroup(array &$i_tokens, int &$i, array $uses, string $namespace): array
    {
        // Collect the inner tokens of this group (between #[ and the matching ]).
        $inner = [];
        $depth = 1;
        $count = count($i_tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $piece = is_array($i_tokens[$j]) ? $i_tokens[$j][1] : $i_tokens[$j];
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
            $inner[] = $i_tokens[$j];
        }

        // Split the inner tokens into individual attributes at top-level commas.
        $attrs = [];
        $cur = [];
        $d = 0;
        foreach ($inner as $tok) {
            $piece = is_array($tok) ? $tok[1] : $tok;
            if (in_array($piece, ['(', '[', '{'], true)) {
                $d++;
            } elseif (in_array($piece, [')', ']', '}'], true)) {
                $d--;
            }
            if ($piece === ',' && $d === 0) {
                if ($cur !== []) {
                    $attrs[] = $cur;
                }
                $cur = [];

                continue;
            }
            $cur[] = $tok;
        }
        if ($cur !== []) {
            $attrs[] = $cur;
        }

        $out = [];
        foreach ($attrs as $attrTokens) {
            $parsed = $this->parseAttr($attrTokens, $uses, $namespace);
            if ($parsed !== null) {
                $out[] = $parsed;
            }
        }

        return $out;
    }

    /**
     * Parse one attribute's tokens into {fqn, arg?/values?}. Returns null for an attribute that is neither
     * LiteralTypeScriptType, In, nor TypeScript (so it is still carried as a bare name for #[TypeScript]).
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  array<string, string>  $uses
     * @return array<string, mixed>|null
     */
    private function parseAttr(array $tokens, array $uses, string $namespace): ?array
    {
        // The name is the leading run of T_STRING / T_NAME_* / '\' up to '(' or end.
        $name = '';
        $k = 0;
        $n = count($tokens);
        for (; $k < $n; $k++) {
            $piece = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            if ($piece === '(') {
                break;
            }
            if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT], true)) {
                continue;
            }
            $name .= $piece;
        }
        $fqn = $this->resolveName($name, $uses, $namespace);

        // Argument tokens are between the first '(' and the last matching ')'.
        $argTokens = [];
        if ($k < $n && (is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k]) === '(') {
            $depth = 0;
            for ($m = $k; $m < $n; $m++) {
                $piece = is_array($tokens[$m]) ? $tokens[$m][1] : $tokens[$m];
                if ($piece === '(') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                }
                if ($piece === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                $argTokens[] = $tokens[$m];
            }
        }

        if ($fqn === self::LITERAL_FQN) {
            return ['fqn' => $fqn, 'arg' => $this->literalArg($argTokens)];
        }
        if ($fqn === self::IN_FQN) {
            return ['fqn' => $fqn, 'values' => $this->splitArgs($argTokens)];
        }
        if ($fqn === self::TYPESCRIPT_FQN) {
            return ['fqn' => $fqn];
        }

        return ['fqn' => $fqn]; // some other attribute in the group — kept so positions are not misread
    }

    /**
     * The `$typeScript` argument of a LiteralTypeScriptType attribute, as a value descriptor:
     * {kind:'string', value} | {kind:'array'} | {kind:'other'}.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $argTokens
     * @return array<string, mixed>
     */
    private function literalArg(array $argTokens): array
    {
        $args = $this->splitArgs($argTokens);
        if ($args === []) {
            return ['kind' => 'other'];
        }
        // Named `typeScript:` wins; else the first positional.
        $chosen = null;
        foreach ($args as $a) {
            if (($a['name'] ?? null) === 'typeScript') {
                $chosen = $a;
                break;
            }
        }
        if ($chosen === null) {
            foreach ($args as $a) {
                if (($a['name'] ?? null) === null) {
                    $chosen = $a;
                    break;
                }
            }
        }
        $chosen ??= $args[0];

        return $this->valueOfTokens($chosen['tokens']);
    }

    /**
     * Reduce a single argument's tokens to a value descriptor.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return array<string, mixed>
     */
    private function valueOfTokens(array $tokens): array
    {
        $sig = array_values(array_filter($tokens, fn ($t) => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT], true))));
        if ($sig === []) {
            return ['kind' => 'other'];
        }
        // Array form: starts with '[' or array( .
        $first = is_array($sig[0]) ? $sig[0][1] : $sig[0];
        if ($first === '[' || (is_array($sig[0]) && $sig[0][0] === T_ARRAY)) {
            return ['kind' => 'array'];
        }
        // A single string literal (possibly the only significant token).
        if (count($sig) === 1 && is_array($sig[0]) && $sig[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return ['kind' => 'string', 'value' => $this->phpStringValue($sig[0][1])];
        }
        // A heredoc/nowdoc body.
        if (is_array($sig[0]) && $sig[0][0] === T_START_HEREDOC) {
            foreach ($sig as $tk) {
                if (is_array($tk) && $tk[0] === T_ENCAPSED_AND_WHITESPACE) {
                    return ['kind' => 'string', 'value' => $tk[1]];
                }
            }
        }

        return ['kind' => 'other']; // concatenation, constant, function call — unclassifiable
    }

    /**
     * Split argument tokens into arguments at top-level commas; each is {name?, tokens}. A leading
     * `ident :` (not `::`) marks a named argument.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $argTokens
     * @return list<array<string, mixed>>
     */
    private function splitArgs(array $argTokens): array
    {
        $args = [];
        $cur = [];
        $depth = 0;
        $n = count($argTokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $argTokens[$i];
            $piece = is_array($tok) ? $tok[1] : $tok;
            if (in_array($piece, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($piece, [')', ']', '}'], true)) {
                $depth--;
            }
            if ($piece === ',' && $depth === 0) {
                $args[] = $this->nameArg($cur);
                $cur = [];

                continue;
            }
            $cur[] = $tok;
        }
        if ($cur !== []) {
            $args[] = $this->nameArg($cur);
        }

        return $args;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return array<string, mixed>
     */
    private function nameArg(array $tokens): array
    {
        $sig = array_values(array_filter($tokens, fn ($t) => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT], true))));
        // Named argument: T_STRING ':' (but not '::').
        if (count($sig) >= 2 && is_array($sig[0]) && $sig[0][0] === T_STRING) {
            $second = is_array($sig[1]) ? $sig[1][1] : $sig[1];
            if ($second === ':') {
                return ['name' => $sig[0][1], 'tokens' => array_slice($sig, 2)];
            }
        }

        return ['name' => null, 'tokens' => $sig];
    }

    /* ---------------- #[In] value + constant resolution ---------------- */

    /**
     * Classify the resolved #[In] argument list: 'string-list' | 'enum' | 'dynamic' | 'other'.
     *
     * @param  list<array<string, mixed>>  $values
     * @param  array<string, string>  $constants
     */
    private function resolveInValues(array $values, string $classFqn, array $constants): string
    {
        $positional = array_values(array_filter($values, fn ($v) => ($v['name'] ?? null) === null));
        if ($positional === []) {
            return 'other';
        }

        // Single argument that is a constant reference or an array.
        if (count($positional) === 1) {
            $resolved = $this->resolveSingleInArg($positional[0]['tokens'], $classFqn, $constants);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // Varargs: every value a string literal -> string-list; any enum case -> enum; any dynamic -> dynamic.
        $allStrings = true;
        $anyEnum = false;
        foreach ($positional as $v) {
            $sig = array_values(array_filter($v['tokens'], fn ($t) => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT], true))));
            if (count($sig) === 1 && is_array($sig[0]) && $sig[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $allStrings = false;
            if ($this->tokensReferenceEnum($sig, $classFqn, $constants)) {
                $anyEnum = true;
            }
        }
        if ($allStrings) {
            return 'string-list';
        }
        if ($anyEnum) {
            return 'enum';
        }

        return 'dynamic';
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  array<string, string>  $constants
     */
    private function resolveSingleInArg(array $tokens, string $classFqn, array $constants): ?string
    {
        $sig = array_values(array_filter($tokens, fn ($t) => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT], true))));
        if ($sig === []) {
            return 'other';
        }
        $first = is_array($sig[0]) ? $sig[0][1] : $sig[0];

        // Inline array: all string literals -> string-list; any enum -> enum; else dynamic.
        if ($first === '[' || (is_array($sig[0]) && $sig[0][0] === T_ARRAY)) {
            $hasString = false;
            $hasEnum = false;
            $hasOther = false;
            foreach ($sig as $tk) {
                if (is_array($tk) && $tk[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $hasString = true;
                } elseif (is_array($tk) && $tk[0] === T_DOUBLE_COLON) {
                    $hasEnum = true; // Foo::Bar inside the array
                } elseif (is_array($tk) && $tk[0] === T_VARIABLE) {
                    $hasOther = true;
                }
            }
            if ($hasEnum && ! $hasString) {
                return 'enum';
            }
            if ($hasString && ! $hasOther) {
                return 'string-list';
            }

            return 'dynamic';
        }

        // `::cases()` or an enum case reference.
        if ($this->tokensReferenceEnum($sig, $classFqn, $constants)) {
            return 'enum';
        }

        // A class constant holding a literal array: resolve through the index.
        if ($this->looksLikeConstRef($sig)) {
            $kind = $this->lookupConstant($sig, $classFqn, $constants);
            if ($kind !== null) {
                return $kind;
            }

            return 'other'; // unresolved constant — counted as unclassified
        }

        if (array_filter($sig, fn ($t) => is_array($t) && $t[0] === T_VARIABLE)) {
            return 'dynamic';
        }

        return null;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $sig
     * @param  array<string, string>  $constants
     */
    private function tokensReferenceEnum(array $sig, string $classFqn, array $constants): bool
    {
        // `X::cases()` always denotes an enum.
        foreach ($sig as $idx => $tk) {
            if (is_array($tk) && $tk[0] === T_STRING && strtolower($tk[1]) === 'cases') {
                return true;
            }
        }
        // `X::Case` where X is a known enum.
        for ($i = 0; $i < count($sig) - 1; $i++) {
            if (is_array($sig[$i]) && $sig[$i][0] === T_STRING && is_array($sig[$i + 1]) && $sig[$i + 1][0] === T_DOUBLE_COLON) {
                $ref = $sig[$i][1] === 'self' || $sig[$i][1] === 'static' ? self::shortName($classFqn) : $sig[$i][1];
                if (isset($constants['enum:'.$ref])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  list<array{0:int,1:string,2:int}|string>  $sig */
    private function looksLikeConstRef(array $sig): bool
    {
        foreach ($sig as $tk) {
            if (is_array($tk) && $tk[0] === T_DOUBLE_COLON) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a `Class::CONST` / `self::CONST` reference to 'string-list' | 'enum' | null via the index.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $sig
     * @param  array<string, string>  $constants
     */
    private function lookupConstant(array $sig, string $classFqn, array $constants): ?string
    {
        $class = null;
        $const = null;
        for ($i = 0; $i < count($sig) - 2; $i++) {
            if (is_array($sig[$i]) && $sig[$i][0] === T_STRING && is_array($sig[$i + 1]) && $sig[$i + 1][0] === T_DOUBLE_COLON
                && is_array($sig[$i + 2]) && $sig[$i + 2][0] === T_STRING) {
                $class = $sig[$i][1];
                $const = $sig[$i + 2][1];
                break;
            }
        }
        if ($class === null || $const === null) {
            return null;
        }
        if ($class === 'self' || $class === 'static') {
            $class = self::shortName($classFqn);
        }

        return $constants['const:'.$class.'::'.$const] ?? null;
    }

    /**
     * Index class constants and enums across every scanned file, so an #[In] constant or default can be
     * resolved by value, not compared as text. Keys: `const:<Short>::<NAME>` => 'string-list'|'enum'|'other';
     * `enum:<Short>` => '1'.
     *
     * @return array<string, string>
     */
    private function indexConstants(): array
    {
        $index = [];
        foreach ($this->files as $file) {
            $code = @file_get_contents($file);
            if ($code === false) {
                continue;
            }
            if (! str_contains($code, 'const') && stripos($code, 'enum ') === false) {
                continue;
            }
            $tokens = token_get_all($code);
            $class = null;
            $n = count($tokens);
            for ($i = 0; $i < $n; $i++) {
                $t = $tokens[$i];
                if (is_array($t) && in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT], true) && $this->prevSignificant($tokens, $i) !== T_DOUBLE_COLON) {
                    $idx = $this->nextSignificant($tokens, $i);
                    $class = ($idx !== null && is_array($tokens[$idx])) ? $tokens[$idx][1] : null;
                }
                if (is_array($t) && $t[0] === T_ENUM) {
                    $idx = $this->nextSignificant($tokens, $i);
                    $class = ($idx !== null && is_array($tokens[$idx])) ? $tokens[$idx][1] : null;
                    if ($class !== null) {
                        $index['enum:'.$class] = '1';
                    }
                }
                if (is_array($t) && $t[0] === T_CONST && $class !== null) {
                    $idx = $this->nextSignificant($tokens, $i);
                    $cname = ($idx !== null && is_array($tokens[$idx])) ? $tokens[$idx][1] : null;
                    if ($cname !== null) {
                        $index['const:'.$class.'::'.$cname] = $this->constValueKind($tokens, $idx);
                    }
                }
            }
        }

        return $index;
    }

    /** Classify a constant's value (after its name) up to ';' : 'string-list' | 'enum' | 'other'. */
    private function constValueKind(array $tokens, int $nameIdx): string
    {
        $n = count($tokens);
        $hasString = false;
        $hasEnum = false;
        $hasArray = false;
        $hasOther = false;
        for ($i = $nameIdx + 1; $i < $n; $i++) {
            $piece = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($piece === ';') {
                break;
            }
            if (is_array($tokens[$i])) {
                if ($tokens[$i][0] === T_ARRAY || $tokens[$i][0] === T_WHITESPACE || $tokens[$i][0] === T_COMMENT) {
                    if ($tokens[$i][0] === T_ARRAY) {
                        $hasArray = true;
                    }

                    continue;
                }
                if ($tokens[$i][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $hasString = true;
                } elseif ($tokens[$i][0] === T_DOUBLE_COLON) {
                    $hasEnum = true;
                } elseif (in_array($tokens[$i][0], [T_VARIABLE, T_STRING], true)) {
                    // T_STRING here is a bare const / fn name — only 'other' unless it is part of ::
                    $hasOther = $hasOther || $tokens[$i][0] === T_VARIABLE;
                }
            } elseif ($piece === '[') {
                $hasArray = true;
            }
        }
        if ($hasEnum && ! $hasString) {
            return 'enum';
        }
        if ($hasString && ! $hasOther) {
            return 'string-list';
        }

        return 'other';
    }

    /* ---------------- declaration helpers ---------------- */

    /**
     * The declared type immediately before a T_VARIABLE: {string: bool (is it string/?string), name: ?string}.
     *
     * @return array{string: bool, name: ?string}
     */
    private function declaredTypeBefore(array $tokens, int $varIdx): array
    {
        // Walk back over the type tokens (skip ws/attributes/visibility/readonly).
        $typeParts = [];
        for ($i = $varIdx - 1; $i >= 0; $i--) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT], true)) {
                if ($typeParts !== []) {
                    // whitespace after collecting a type name means we are done
                    break;
                }

                continue;
            }
            $piece = is_array($t) ? $t[1] : $t;
            if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PRIVATE, T_PROTECTED, T_READONLY, T_VAR, T_STATIC], true)) {
                break;
            }
            if ($piece === ',' || $piece === '(' || $piece === '{' || $piece === ';' || (is_array($t) && $t[0] === T_ATTRIBUTE)) {
                break;
            }
            if ($piece === ']') { // end of an attribute group
                break;
            }
            if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY], true)) {
                array_unshift($typeParts, $piece);

                continue;
            }
            if ($piece === '?' || $piece === '|' || $piece === '\\') {
                array_unshift($typeParts, $piece);

                continue;
            }
            break;
        }
        $type = implode('', $typeParts);
        $bare = ltrim($type, '?');
        $isString = strtolower($bare) === 'string';
        $name = ($bare === '' || $isString) ? null : $bare;

        return ['string' => $isString, 'name' => $name];
    }

    /**
     * The property/parameter default after a `$var`, as a value descriptor for comparison:
     * ['string'=>value] | ['const'=>'Class::NAME'] | null (no default).
     *
     * @return array<string, string>|null
     */
    private function readDefault(array $tokens, int $varIdx): ?array
    {
        $j = $this->nextSignificant($tokens, $varIdx);
        if ($j === null || (is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j]) !== '=') {
            return null;
        }

        $value = [];
        $depth = 0;
        $n = count($tokens);
        for ($k = $j + 1; $k < $n; $k++) {
            $t = $tokens[$k];
            $piece = is_array($t) ? $t[1] : $t;
            if (in_array($piece, ['(', '[', '{'], true)) {
                $depth++;
            }
            if (in_array($piece, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
            if ($depth === 0 && ($piece === ',' || $piece === ';')) {
                break;
            }
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $value[] = $t;
        }

        if ($value === []) {
            return null;
        }
        if (count($value) === 1 && is_array($value[0]) && $value[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return ['string' => $this->phpStringValue($value[0][1])];
        }
        // Class::CONST default.
        if ($this->looksLikeConstRef($value)) {
            $class = null;
            $const = null;
            for ($i = 0; $i < count($value) - 2; $i++) {
                if (is_array($value[$i]) && $value[$i][0] === T_STRING && is_array($value[$i + 1]) && $value[$i + 1][0] === T_DOUBLE_COLON
                    && is_array($value[$i + 2]) && $value[$i + 2][0] === T_STRING) {
                    $class = $value[$i][1];
                    $const = $value[$i + 2][1];
                    break;
                }
            }
            if ($class !== null && $const !== null) {
                return ['const' => $class.'::'.$const];
            }
        }

        return ['other' => '1'];
    }

    /**
     * Resolve a site's default to [value] (a concrete string), [null] (no/non-string default), or
     * ['unresolved'] (a constant we could not resolve).
     *
     * @param  array<string, mixed>  $site
     * @param  array<string, string>  $constants
     * @return array<int|string, mixed>
     */
    private function resolveDefault(array $site, array $constants): array
    {
        $default = $site['default'] ?? null;
        if ($default === null) {
            return [null];
        }
        if (isset($default['string'])) {
            return [$default['string']];
        }
        if (isset($default['const'])) {
            // We index whether a constant is a string-list/enum, not its scalar value; a scalar string
            // constant is 'other'. Either way we cannot compare it to the literal by value, so it is
            // unresolved rather than a text comparison.
            return ['unresolved'];
        }

        return [null]; // a non-string default (null keyword, number) — there is no matching string default
    }

    private function docblockLiteralUnionFor(?string $docblock, string $member): ?string
    {
        if ($docblock === null) {
            return null;
        }
        if (! preg_match('/@(?:param|var)\s+([^\r\n$]*?)\s+\$'.preg_quote($member, '/').'\b/', $docblock, $m)
            && ! preg_match('/@(?:param|var)\s+\$'.preg_quote($member, '/').'\s+([^\r\n]*)/', $docblock, $m)) {
            return null;
        }
        $type = trim($m[1]);

        return $this->isLiteralUnion($type) ? $type : null;
    }

    private function extendsData(array $tokens, int $classIdx, array $uses, string $namespace): bool
    {
        $n = count($tokens);
        for ($i = $classIdx + 1; $i < $n; $i++) {
            $piece = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            if ($piece === '{' || $piece === ';') {
                return false;
            }
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_EXTENDS) {
                $idx = $this->nextSignificant($tokens, $i);
                $parent = ($idx !== null && is_array($tokens[$idx])) ? $tokens[$idx][1] : '';

                return $this->resolveName($parent, $uses, $namespace) === self::DATA_FQN
                    || str_ends_with($parent, 'Data'); // a family Data base (…\Data) also counts
            }
        }

        return false;
    }

    /* ---------------- pending-attribute helpers ---------------- */

    /** @param  list<array<string, mixed>>  $pending */
    private function pendingHasAttr(array $pending, string $fqn): bool
    {
        foreach ($pending as $a) {
            if (($a['fqn'] ?? null) === $fqn) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     * @return list<array<string, mixed>>
     */
    private function pendingAttrs(array $pending, string $fqn): array
    {
        return array_values(array_filter($pending, fn ($a) => ($a['fqn'] ?? null) === $fqn));
    }

    /**
     * @param  list<array<string, mixed>>  $pending
     * @return array<string, mixed>|null
     */
    private function firstAttr(array $pending, string $fqn): ?array
    {
        foreach ($pending as $a) {
            if (($a['fqn'] ?? null) === $fqn) {
                return $a;
            }
        }

        return null;
    }

    /* ---------------- token scanning primitives ---------------- */

    private function nextSignificant(array $tokens, int $i): ?int
    {
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    /** The token id (int), single-char text (string), or null of the previous significant token. */
    private function prevSignificant(array $tokens, int $i): int|string|null
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($tokens[$j]) ? $tokens[$j][0] : $tokens[$j];
        }

        return null;
    }

    private function readName(array $tokens, int $i): string
    {
        $name = '';
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; $j++) {
            $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            if ($piece === ';' || $piece === '{') {
                break;
            }
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }
            $name .= $piece;
        }

        return trim($name);
    }

    private function phpStringValue(string $tokenText): string
    {
        $tokenText = trim($tokenText);
        if ($tokenText === '') {
            return '';
        }
        $q = $tokenText[0];
        $inner = substr($tokenText, 1, -1);
        if ($q === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }

        return stripcslashes($inner);
    }

    private static function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    private static function rel(string $file): string
    {
        $pos = strpos($file, '/src/');

        return $pos !== false ? substr($file, $pos + 1) : basename($file);
    }

    private function shorten(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));

        return strlen($s) > 80 ? substr($s, 0, 77).'…' : $s;
    }
}
