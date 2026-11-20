<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Surgeon\Support\HostScanRoots;

/**
 * attention-surfaces 16 — the closed-vocabulary audit (decision 03, the expand step). A sibling to
 * {@see DeclarationDocblockAudit}: registered advisory the same way, scanning the same roots (a host's
 * own source plus the family packages it composes), and emitting a census line on every run so an empty
 * report is distinguishable from an unread one.
 *
 * It finds the `#[LiteralTypeScriptType]` attribute by NAME in any position — imported or fully
 * qualified, first or later in an attribute group — on a property, a constructor parameter, or a class,
 * and reads the one thing {@see DeclarationDocblockAudit} never looks at: the attribute's ARGUMENT. It
 * also finds a Data class's `string` property that carries no such attribute but whose constructor
 * docblock types it as a union of quoted string literals.
 *
 * ## Why advisory (ruling 6)
 *
 * Whether a set of strings is CLOSED is a judgement (an open set — one a host's enum can extend — is not
 * a union), and which packages a host composes is a host fact. Both keep this advisory: it reports, it
 * does not gate. It converts no property, so the seven-line compatibility rule does not bind it; the
 * single-literal check is the cheap half of ruling 4 (a discriminator literal should equal its default).
 *
 * ## The four Warn checks
 *
 *  - {@see CHECK_VOCABULARY} — the argument is a union of two or more quoted string literals, with or
 *    without `null`. This is a closed vocabulary; it should be a backed enum.
 *  - {@see CHECK_INLINE_OBJECT} — the argument is an object type with named members. This is a Data
 *    class, not a hand-typed shape.
 *  - {@see CHECK_DISCRIMINATOR_MISMATCH} — the argument is one quoted string literal, and the property's
 *    default is missing or a different string. A single literal is legitimate ONLY as the arm a
 *    discriminated union is narrowed to, which is the property's own default.
 *  - {@see CHECK_DOCBLOCK_VOCABULARY} — a Data class `string` property with no type attribute whose
 *    constructor docblock types it as a union of quoted string literals (the `unknown`-emitting case).
 *
 * ## The census (always emitted)
 *
 * Every other argument is reported by nothing and counted into {@see CHECK_CENSUS}, which counts the
 * four non-warned classes apart: a single literal equal to its default (a clean discriminator), a
 * numeric literal (a version), a structural type a PHPDoc type or `#[TypeScriptType]` could express (a
 * record, a list, a keyed or indexed object), and an inference override the transpiler cannot place
 * (`unknown`, a union carrying a non-literal member). The census also reports the warned counts, so one
 * line describes the whole population. Decision 03's F-numbers freeze a snapshot; this line is the live
 * count — read it, not the frozen numbers.
 */
class ClosedVocabularyAudit implements DoctorAudit
{
    /** The argument is a union of 2+ quoted string literals (± null): a closed vocabulary. Warn. */
    public const CHECK_VOCABULARY = 'typescript.literal-vocabulary';

    /** The argument is an object type with named members: a Data class, not a hand-typed shape. Warn. */
    public const CHECK_INLINE_OBJECT = 'typescript.literal-inline-object';

    /** One quoted string literal whose property default is missing or a different string. Warn. */
    public const CHECK_DISCRIMINATOR_MISMATCH = 'typescript.literal-discriminator-mismatch';

    /** A Data class string property with no attribute whose constructor docblock is a literal union. Warn. */
    public const CHECK_DOCBLOCK_VOCABULARY = 'typescript.docblock-vocabulary';

    /** The census line — always emitted, so an empty report is distinguishable from an unread one. */
    public const CHECK_CENSUS = 'typescript.closed-vocabulary-census';

    /** The attribute this audit is about, as its fully qualified name. */
    public const ATTRIBUTE_FQN = 'Spatie\\TypeScriptTransformer\\Attributes\\LiteralTypeScriptType';

    /** Directory names never descended into (each family package carries its own dev vendor tree). */
    public const SKIP_DIRS = ['vendor', 'node_modules', 'dist', '.git'];

    /**
     * @param  list<string>  $files  absolute paths of the PHP files to scan
     */
    public function __construct(
        private array $files,
    ) {}

    /**
     * Host-scoped construction: every host source dir plus one root per family package it composes,
     * resolved through the vendor symlink by {@see HostScanRoots} — the same roots the sibling audit
     * scans.
     *
     * @param  list<string>|null  $roots  PHP scan roots, defaulting to the host's
     */
    public static function forApp(?array $roots = null): self
    {
        $roots = $roots ?? HostScanRoots::resolve();

        $files = [];
        foreach ($roots as $root) {
            foreach (DeclarationDocblockAudit::filesUnder($root, ['php']) as $file) {
                $files[] = $file;
            }
        }

        return new self($files);
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

        $findings = [];
        $census = [
            'discriminator' => 0,
            'version' => 0,
            'structural' => 0,
            'inference-override' => 0,
            'vocabulary' => 0,
            'inline-object' => 0,
            'docblock-vocabulary' => 0,
        ];

        foreach ($this->files as $file) {
            foreach ($this->sitesIn($file) as $site) {
                [$finding, $class] = $this->classify($site, $file);
                $census[$class]++;
                if ($finding !== null) {
                    $findings[] = $finding;
                }
            }
        }

        array_unshift($findings, Finding::pass(self::CHECK_CENSUS, sprintf(
            '%d PHP file(s) scanned, %d #[LiteralTypeScriptType] / docblock literal site(s): '
            .'%d discriminator, %d version, %d structural, %d inference-override (reported by nothing); '
            .'%d literal-vocabulary, %d inline-object, %d docblock-vocabulary (warned).',
            count($this->files),
            array_sum($census),
            $census['discriminator'],
            $census['version'],
            $census['structural'],
            $census['inference-override'],
            $census['vocabulary'],
            $census['inline-object'],
            $census['docblock-vocabulary'],
        )));

        return $findings;
    }

    /**
     * Classify one site into (Finding|null, census-class).
     *
     * @param  array<string, mixed>  $site
     * @return array{0: Finding|null, 1: string}
     */
    private function classify(array $site, string $file): array
    {
        $where = sprintf('%s::%s', self::shortName($site['class']), $site['member']);

        if ($site['kind'] === 'docblock') {
            return [Finding::warn(self::CHECK_DOCBLOCK_VOCABULARY, sprintf(
                '%s (%s): a Data class `string` property has no #[LiteralTypeScriptType] and its '
                .'constructor docblock types it as the literal union %s — which emits `unknown`. Type it a '
                .'backed enum (closed-vocabulary.convention.md).',
                $where, self::rel($file), $this->shorten($site['argument'])
            )), 'docblock-vocabulary'];
        }

        $arg = trim($site['argument']);

        if ($this->isInlineObject($arg)) {
            return [Finding::warn(self::CHECK_INLINE_OBJECT, sprintf(
                '%s (%s): the #[LiteralTypeScriptType] argument %s is an object with named members. An '
                .'object is a Data class, never a hand-typed shape (closed-vocabulary.convention.md).',
                $where, self::rel($file), $this->shorten($arg)
            )), 'inline-object'];
        }

        if ($this->isLiteralUnion($arg)) {
            return [Finding::warn(self::CHECK_VOCABULARY, sprintf(
                '%s (%s): the #[LiteralTypeScriptType] argument %s is a closed set of strings. Type it a '
                .'PHP backed enum; the case values are the wire values (closed-vocabulary.convention.md).',
                $where, self::rel($file), $this->shorten($arg)
            )), 'vocabulary'];
        }

        if (($literal = $this->singleStringLiteral($arg)) !== null) {
            $default = $site['default'];
            if ($default === null || $default !== $literal) {
                return [Finding::warn(self::CHECK_DISCRIMINATOR_MISMATCH, sprintf(
                    '%s (%s): the single #[LiteralTypeScriptType] literal %s %s. A single literal is the '
                    .'arm a discriminated union is narrowed to, and must equal the property default.',
                    $where, self::rel($file), $this->shorten($arg),
                    $default === null ? 'has no property default' : sprintf('does not equal the default %s', var_export($default, true))
                )), 'discriminator'];
            }

            return [null, 'discriminator'];
        }

        if ($this->isNumericLiteral($arg)) {
            return [null, 'version'];
        }

        if ($this->isStructural($arg)) {
            return [null, 'structural'];
        }

        return [null, 'inference-override'];
    }

    /* ---------------- classification of a TypeScript type argument ---------------- */

    private function isInlineObject(string $arg): bool
    {
        // An object type with at least one named member: `{ foo: ...; bar: ... }`. An index signature
        // (`{ [k: string]: T }`) is a record, not named members, so it is structural, not this.
        return (bool) preg_match('/\{\s*(?!\s*\[)[A-Za-z_$][\w$]*\s*\??\s*:/', $arg);
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
                return false; // a non-literal, non-null member — not a closed vocabulary
            }
        }

        return $literals >= 2;
    }

    private function singleStringLiteral(string $arg): ?string
    {
        $parts = $this->unionParts($arg);
        $nonNull = array_values(array_filter($parts, fn (string $p) => strtolower($p) !== 'null'));

        if (count($nonNull) === 1 && $this->isStringLiteral($nonNull[0])) {
            return $this->unquote($nonNull[0]);
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
        // A type a PHPDoc type or #[TypeScriptType] could express structurally: a generic (`list<…>`,
        // `Record<…>`, `Array<…>`), an array (`T[]`), or an object/record literal (an index signature or
        // a members block). `unknown`/`any`/`mixed` and a bare identifier are NOT structural.
        return (bool) preg_match('/<|\[\s*\]|\{/', $arg);
    }

    /**
     * Split a TypeScript union at its TOP-LEVEL `|`, so a `|` inside `{...}`, `<...>`, `[...]` or a
     * string literal does not split a member.
     *
     * @return list<string>
     */
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
                if ($ch === $quote) {
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
        $part = trim($part);

        return (bool) preg_match('/^([\'"]).*\1$/s', $part);
    }

    private function unquote(string $part): string
    {
        $part = trim($part);

        return substr($part, 1, -1);
    }

    /* ---------------- token parsing: find the attribute sites and docblock unions ---------------- */

    /**
     * Every site in one file: a `#[LiteralTypeScriptType(...)]` on a property, parameter or class
     * (kind `attribute`, carrying its argument string and the declaration's default), and a Data class
     * `string` property with no such attribute whose constructor docblock is a literal union (kind
     * `docblock`).
     *
     * @return list<array<string, mixed>>
     */
    public function sitesIn(string $file): array
    {
        $code = @file_get_contents($file);
        if ($code === false || ! str_contains($code, 'LiteralTypeScriptType') && ! str_contains($code, 'TypeScript')) {
            return [];
        }

        $tokens = token_get_all($code);
        $local = $this->attributeNames($tokens);

        $sites = [];
        $namespace = '';
        $classStack = []; // [name, isTypeScript, braceDepthAtOpen]
        $braceDepth = 0;
        $pendingAttr = null; // the LiteralTypeScriptType argument captured for the next declaration
        $pendingClassAttrs = ''; // raw attribute text before a class, to read #[TypeScript]
        $constructorDoc = null;
        $pendingDoc = null;
        $signatureParen = null; // paren depth of the constructor parameter list, or null
        $inConstructor = false;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $text = is_array($t) ? $t[1] : $t;

            if (is_array($t) && $t[0] === T_DOC_COMMENT) {
                $pendingDoc = $t[1];

                continue;
            }

            if (is_array($t) && $t[0] === T_NAMESPACE) {
                $namespace = $this->readDottedName($tokens, $i);

                continue;
            }

            if ($t === '{') {
                $braceDepth++;

                continue;
            }

            if ($t === '}') {
                $braceDepth--;
                if ($classStack !== [] && $braceDepth === end($classStack)['depth']) {
                    array_pop($classStack);
                    $constructorDoc = null;
                }

                continue;
            }

            // Attribute group: capture the LiteralTypeScriptType argument (anywhere in the group) and
            // keep the raw text so a following class declaration can read #[TypeScript].
            if (is_array($t) && $t[0] === T_ATTRIBUTE) {
                $raw = $this->readAttributeGroup($tokens, $i); // advances $i to the matching ]
                $pendingClassAttrs = $raw;
                $arg = $this->literalArgumentIn($raw, $local);
                if ($arg !== null) {
                    $pendingAttr = $arg;
                }

                continue;
            }

            if (is_array($t) && ($t[0] === T_CLASS || $t[0] === T_ENUM || $t[0] === T_INTERFACE || $t[0] === T_TRAIT)) {
                $next = $this->nextMeaningful($tokens, $i);
                $name = ($next !== null && is_array($tokens[$next])) ? $tokens[$next][1] : '';
                $fqn = ($namespace === '' ? '' : $namespace.'\\').$name;
                $isTs = str_contains($pendingClassAttrs, 'TypeScript');

                // A class-level #[LiteralTypeScriptType] is its own site.
                if ($pendingAttr !== null) {
                    $sites[] = ['kind' => 'attribute', 'class' => $fqn, 'member' => '<class>', 'argument' => $pendingAttr, 'default' => null];
                    $pendingAttr = null;
                }

                $classStack[] = ['name' => $fqn, 'typescript' => $isTs, 'depth' => $braceDepth];
                $pendingClassAttrs = '';
                $constructorDoc = null;

                continue;
            }

            // Entering the constructor: remember its docblock for the docblock-vocabulary check.
            if (is_array($t) && $t[0] === T_FUNCTION) {
                $next = $this->nextMeaningful($tokens, $i);
                $fname = ($next !== null && is_array($tokens[$next])) ? $tokens[$next][1] : '';
                $inConstructor = ($fname === '__construct');
                if ($inConstructor) {
                    $constructorDoc = $pendingDoc;
                }
                $pendingAttr = null; // a method's own attributes do not carry past it
            }

            // A variable in a declaration position carries the pending attribute (property or promoted
            // param) or, with no attribute, may match a docblock literal union.
            if (is_array($t) && $t[0] === T_VARIABLE) {
                $member = ltrim($t[1], '$');
                $class = $classStack === [] ? ['name' => '', 'typescript' => false] : end($classStack);
                $default = $this->readDefault($tokens, $i);

                if ($pendingAttr !== null) {
                    $sites[] = ['kind' => 'attribute', 'class' => $class['name'], 'member' => $member, 'argument' => $pendingAttr, 'default' => $default];
                    $pendingAttr = null;
                } elseif ($class['typescript']) {
                    $union = $this->docblockLiteralUnionFor($constructorDoc ?? $pendingDoc, $member);
                    if ($union !== null) {
                        $sites[] = ['kind' => 'docblock', 'class' => $class['name'], 'member' => $member, 'argument' => $union, 'default' => $default];
                    }
                }

                $pendingDoc = null;

                continue;
            }

            if (is_array($t) && ! in_array($t[0], [T_WHITESPACE, T_COMMENT], true)) {
                // any other meaningful token clears a dangling attribute only if it is not part of a type
                // between the attribute and its variable; keep it simple by clearing on `;`.
            }

            if ($t === ';') {
                $pendingAttr = null;
                $pendingDoc = null;
            }
        }

        return $sites;
    }

    /**
     * The local names that denote the attribute in this file: `LiteralTypeScriptType` unless aliased,
     * the alias if `use … as X`, and the trailing-segment form when the namespace itself is imported.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return list<string>
     */
    private function attributeNames(array $tokens): array
    {
        $names = ['LiteralTypeScriptType'];
        $code = '';
        foreach ($tokens as $t) {
            $code .= is_array($t) ? $t[1] : $t;
        }

        if (preg_match('/use\s+Spatie\\\\TypeScriptTransformer\\\\Attributes\\\\LiteralTypeScriptType\s+as\s+(\w+)\s*;/', $code, $m)) {
            $names[] = $m[1];
        }
        if (preg_match('/use\s+Spatie\\\\TypeScriptTransformer\\\\Attributes\s*;/', $code)) {
            $names[] = 'Attributes\\LiteralTypeScriptType';
        }

        return array_values(array_unique($names));
    }

    /**
     * The first string-literal argument of a `LiteralTypeScriptType` attribute inside a raw attribute
     * group, matching the name in any position (first or later, imported or fully qualified), or null.
     *
     * @param  list<string>  $local
     */
    private function literalArgumentIn(string $raw, array $local): ?string
    {
        // Build an alternation of the ways the attribute can be named.
        $alts = ['\\\\?Spatie\\\\TypeScriptTransformer\\\\Attributes\\\\LiteralTypeScriptType'];
        foreach ($local as $name) {
            $alts[] = preg_quote($name, '/');
        }
        $pattern = '/(?:'.implode('|', $alts).')\s*\(\s*([\'"])(.*?)\1/s';

        if (preg_match($pattern, $raw, $m)) {
            return $m[2];
        }

        return null;
    }

    /** Read a `#[ ... ]` attribute group from its T_ATTRIBUTE token, advancing $i to the matching `]`. */
    private function readAttributeGroup(array $tokens, int &$i): string
    {
        $text = '#[';
        $depth = 1;
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            if ($piece === '[') {
                $depth++;
            }
            if ($piece === ']') {
                $depth--;
                if ($depth === 0) {
                    $i = $j;

                    return $text.']';
                }
            }
            $text .= $piece;
        }
        $i = $count - 1;

        return $text;
    }

    /** The property/parameter default after a `$var`, as a source string, or null when there is none. */
    private function readDefault(array $tokens, int $i): ?string
    {
        $j = $this->nextMeaningful($tokens, $i);
        if ($j === null || (is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j]) !== '=') {
            return null;
        }

        $buf = '';
        $depth = 0;
        $count = count($tokens);
        for ($k = $j + 1; $k < $count; $k++) {
            $piece = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
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
            if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $buf .= $piece;
        }

        $buf = trim($buf);
        if ($buf === '') {
            return null;
        }
        // A quoted string default -> its value; anything else (null, const, expr) -> the source text.
        if (preg_match('/^([\'"])(.*)\1$/s', $buf, $m)) {
            return $m[2];
        }

        return $buf;
    }

    /**
     * The literal union a constructor docblock gives `$member`, when it is a union of two or more quoted
     * string literals, or null.
     */
    private function docblockLiteralUnionFor(?string $docblock, string $member): ?string
    {
        if ($docblock === null) {
            return null;
        }

        if (! preg_match('/@(?:param|var)\s+([^\r\n]*?)\s+\$'.preg_quote($member, '/').'\b/', $docblock, $m)) {
            // @var with no name, or @param with the name first — try the name-then-type shapes too.
            if (! preg_match('/@(?:param|var)\s+\$'.preg_quote($member, '/').'\s+([^\r\n]*)/', $docblock, $m)) {
                return null;
            }
        }

        $type = trim($m[1]);

        return $this->isLiteralUnion($type) ? $type : null;
    }

    /* ---------------- small token helpers ---------------- */

    private function nextMeaningful(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    private function readDottedName(array $tokens, int $i): string
    {
        $name = '';
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            $piece = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            if ($piece === ';' || $piece === '{') {
                break;
            }
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE], true)) {
                continue;
            }
            $name .= $piece;
        }

        return trim($name);
    }

    private static function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }

    private static function rel(string $file): string
    {
        $pos = strpos($file, '/src/');
        if ($pos !== false) {
            return substr($file, $pos + 1);
        }

        return basename($file);
    }

    private function shorten(string $s): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));

        return strlen($s) > 80 ? substr($s, 0, 77).'…' : $s;
    }
}
