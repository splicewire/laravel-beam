<?php

namespace Splicewire\Beam\Doctor;

use Attribute;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\Support\MarketingCopySource;
use Throwable;

/**
 * A code sample in marketing copy that names something the estate does not provide (competitive-landscape
 * ticket 06).
 *
 * ## Why this exists, and why it is not proofreading
 *
 * Two defects of the same class shipped on the `/beam` page. A **fabricated sample** (`BeamSchema` /
 * `beam:schema`, neither of which exists) was caught by review. A **wrong command name** —
 * `php artisan make:particle-resource Article` in three places, where the registered signature is
 * `splicewire:beam:make:particle-resource` and no alias exists — was not: a visitor who copy-pasted the
 * terminal line got *"command is not defined."* research/04's conclusion was *"that argues for a check
 * rather than another round of proofreading."*
 *
 * A sample-by-sample verification of all 16 samples then measured the population (2026-09-03) and
 * corrected the check's own design in the process. Ticket 06 §Scope asked for artisan-name resolution
 * first; the two most expensive defects found were **not** artisan names. They were a *package* name
 * (`composer create-project splicewire/beam-starter` — nothing resolves that spelling), and *missing
 * required constructor arguments* (`#[ParticleResource(label:…, group:…)]`, which is an
 * `ArgumentCountError` as shipped, because `key:` and `backing:` are required). So this audit reads three
 * claim kinds, and the ordering is the measurement, not a guess:
 *
 *  1. **`…package`** — `composer require|create-project <vendor>/<name>` resolves against the estate
 *     MANIFEST: `vendor/composer/installed.json`, plus the root package's own name. Never
 *     `composer.local.json` — the overlay states an intent and the sweep needs a fact (AGENTS.md), and at
 *     the roots where the merge plugin is not installed the overlay is a file composer never read.
 *  2. **`…command`** — `php artisan <cmd>` exists in the BOOTED host's command table. A registered name is
 *     a fact about what boots here, not about what a grep of `src/` can see.
 *  3. **`…attribute`** — an `#[Attribute(...)]` sample is INSTANTIATED by reflection. Checking that the
 *     class exists is what a name-resolver would do, and a name-resolver would have passed the shipped
 *     `#[ParticleResource(label:…, group:…)]`. Instantiation is what makes a missing required parameter
 *     fail loudly, which is the whole reason this kind is here.
 *
 * ## Advisory, permanently
 *
 * Whether a host publishes marketing copy at all — and which spellings its copy is allowed to use — is a
 * fact about the HOST, so every row is a `Warn` and never a `Fail` (AGENTS.md: "a check whose answer
 * depends on the host must not throw"). A pre-launch page may legitimately name a package that is not on
 * packagist yet; the audit's job is to make sure nobody discovers that from a visitor.
 *
 * ## The two kinds of source, because the disk file is not the page
 *
 * `paths` globs reach island source and markdown. {@see MarketingCopySource} providers reach copy that is
 * not a file — at `splicewire/splicewire` the prose bullets live in a ~49 KB `beam_particles` payload
 * whose disk neighbour is an outbound projection written on Publish. Both are in scope, and a host points
 * this audit at its own copy through `beam.core.marketing_copy`. Beam ships NO default population: an
 * unconfigured host is inconclusive, not clean.
 *
 * ## Reading a code sample out of a JS/TS island
 *
 * A PHP sample inside a `.tsx` island is fragmented across JSX string literals
 * (`{tok('attr', '#[ParticleResource(')}` … `{tok('attr', ')]')}`), so a balanced-bracket scan of the raw
 * file matches JSX and produces garbage. For a JS/TS document the attribute scan therefore runs over the
 * file's string literals joined in source order — which reconstructs the sample exactly, because the
 * literal order IS the code order. Composer and artisan lines are scanned in the RAW text as well, since
 * those also appear as JSX text nodes and prop values outside any literal.
 *
 * ## The did-not-look counter
 *
 * A document that cannot be read, and an attribute span that is not a constant expression (so it is not
 * safe to instantiate, and is probably not a real sample), are COUNTED and named — never warned about and
 * never silently dropped. An audit that enumerates its known blind spots and not its unknown ones reads as
 * thorough exactly where it is weakest (AGENTS.md, "reach before precision"). An empty population is
 * inconclusive and NAMES the candidate count, so "no claims here" cannot be confused with "did not look".
 */
class MarketingSampleAudit implements DoctorAudit
{
    public const CHECK = 'beam.marketing.sample-claim';

    public const CHECK_PACKAGE = 'beam.marketing.sample-claim.package';

    public const CHECK_COMMAND = 'beam.marketing.sample-claim.command';

    public const CHECK_ATTRIBUTE = 'beam.marketing.sample-claim.attribute';

    /**
     * Where a bare attribute short name is looked for. A host adds its own; these are the namespaces a
     * beam sample can legitimately spell without qualifying.
     */
    public const DEFAULT_ATTRIBUTE_NAMESPACES = [
        // `Splicewire\Beam\Particle\Attributes` FIRST, and the order is load-bearing: two classes are
        // named `ParticleResource` — the attribute, and the runtime value object beside it in
        // `Splicewire\Beam\Particle`. A first-match-wins resolver that took the value object reported the
        // corrected sample as broken ("Attempting to use non-attribute class …"), which is a finding about
        // the resolver rather than about the copy. {@see resolveAttributeClass()} additionally requires an
        // `#[Attribute]` marker, so the ordering is belt and braces rather than the whole rule.
        'Splicewire\\Beam\\Particle\\Attributes',
        'Splicewire\\Beam\\Particle',
        'Splicewire\\Beam\\Data',
        'Spatie\\LaravelData\\Attributes',
        'Spatie\\LaravelData\\Attributes\\Validation',
        // The three open foundations beam depends DOWN on in `composer.json` — frame (ADR-0156),
        // data-schemas, data-filters. Their attributes are as legitimately unqualified in a beam sample as
        // beam's own, and leaving them out produced a FABRICATED finding rather than a missing one:
        // `~/Herd/splicewire`'s Frame docs show `#[Widget]`, which the estate ships at
        // `Schemastud\Frame\Attributes\Widget`, and the audit reported "resolves to no attribute class in
        // this estate" twice. A wrong label on a real finding is the one thing this file must not emit.
        'Schemastud\\Frame\\Attributes',
        'Schemastud\\DataSchemas\\Attributes',
        'Rushing\\DataFilters\\Attributes',
    ];

    /** Extensions whose PHP samples live inside string literals rather than in the file's own grammar. */
    public const LITERAL_EXTENSIONS = ['js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs'];

    /**
     * @param  list<string>  $paths  glob patterns, absolute or relative to the app base path
     * @param  list<class-string<MarketingCopySource>|MarketingCopySource>  $providers
     * @param  list<string>  $attributeNamespaces
     * @param  list<string>|null  $commands  registered command names; null reads the booted host's table
     */
    public function __construct(
        protected array $paths = [],
        protected array $providers = [],
        protected array $attributeNamespaces = self::DEFAULT_ATTRIBUTE_NAMESPACES,
        protected ?string $installedJsonPath = null,
        protected ?array $commands = null,
        protected ?string $rootComposerPath = null,
    ) {}

    /** @var list<string>|null memoised estate manifest, read once per run */
    protected ?array $installed = null;

    public static function forApp(): self
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('beam.core.marketing_copy', []);

        return new self(
            paths: array_values((array) ($config['paths'] ?? [])),
            providers: array_values((array) ($config['providers'] ?? [])),
            attributeNamespaces: array_values(array_unique(array_merge(
                self::DEFAULT_ATTRIBUTE_NAMESPACES,
                (array) ($config['attribute_namespaces'] ?? []),
            ))),
            installedJsonPath: base_path('vendor/composer/installed.json'),
            rootComposerPath: base_path('composer.json'),
        );
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $unreadable = [];
        $documents = $this->documents($unreadable);

        if ($documents === [] && $unreadable === []) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'No marketing copy is configured on this host: `beam.core.marketing_copy.paths` matched no '
                .'file and %d provider%s contributed a document. Beam ships no default population — a host '
                .'points this audit at its own island source and at whatever carries its prose (at '
                .'splicewire/splicewire that is a particle payload, not the disk projection beside it). '
                .'Nothing was measured.',
                count($this->providers),
                count($this->providers) === 1 ? '' : 's',
            ))];
        }

        $claims = [];
        $unparsed = [];

        foreach ($documents as $label => $text) {
            foreach ($this->claims($label, $text, $unparsed) as $claim) {
                $claims[$claim['kind'].'|'.$claim['subject'].'|'.$label] = $claim;
            }
        }

        $findings = [];

        foreach ($claims as $claim) {
            $finding = match ($claim['kind']) {
                'package' => $this->verifyPackage($claim),
                'command' => $this->verifyCommand($claim),
                default => $this->verifyAttribute($claim),
            };

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $blindSpot = $this->blindSpot($unreadable, $unparsed);

        if ($claims === []) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'Read %d marketing document%s and found no `composer <vendor>/<name>`, no `php artisan <cmd>` '
                .'and no `#[Attribute(...)]` sample in any of them — there was nothing to resolve against the '
                .'estate.%s',
                count($documents),
                count($documents) === 1 ? '' : 's',
                $blindSpot,
            ))];
        }

        if ($findings === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d claim%s across %d marketing document%s resolve against this estate: %s.%s',
                count($claims),
                count($claims) === 1 ? '' : 's',
                count($documents),
                count($documents) === 1 ? '' : 's',
                $this->tally($claims),
                $blindSpot,
            ))];
        }

        if ($blindSpot !== '') {
            $findings[] = Finding::inconclusive(self::CHECK, sprintf(
                'Reach note for the %d row%s above:%s',
                count($findings),
                count($findings) === 1 ? '' : 's',
                $blindSpot,
            ));
        }

        return $findings;
    }

    /**
     * @param  list<string>  $unreadable
     * @return array<string, string>
     */
    protected function documents(array &$unreadable): array
    {
        $documents = [];

        foreach ($this->paths as $pattern) {
            $pattern = $this->absolute($pattern);

            foreach (glob($pattern, GLOB_BRACE) ?: [] as $file) {
                if (! is_file($file)) {
                    continue;
                }

                $text = @file_get_contents($file);

                if ($text === false) {
                    $unreadable[] = $file;

                    continue;
                }

                $documents[$file] = $text;
            }
        }

        foreach ($this->providers as $provider) {
            try {
                $source = is_string($provider) ? app($provider) : $provider;

                if (! $source instanceof MarketingCopySource) {
                    $unreadable[] = is_string($provider) ? $provider : $source::class;

                    continue;
                }

                foreach ($source->documents() as $label => $text) {
                    $documents[(string) $label] = (string) $text;
                }
            } catch (Throwable $e) {
                $unreadable[] = (is_string($provider) ? $provider : $provider::class).' ('.$e->getMessage().')';
            }
        }

        return $documents;
    }

    protected function absolute(string $pattern): string
    {
        if (str_starts_with($pattern, '/')) {
            return $pattern;
        }

        return function_exists('base_path') ? base_path($pattern) : $pattern;
    }

    /**
     * @param  list<array{document: string, text: string}>  $unparsed
     * @return list<array{kind: string, subject: string, document: string, raw: string}>
     */
    protected function claims(string $label, string $text, array &$unparsed): array
    {
        $claims = [];

        // Composer package names and artisan command names are scanned in the RAW text: in a JSX island
        // they sit in text nodes and prop values, outside any string literal the sample lives in.
        if (preg_match_all(
            '/\bcomposer\s+(?:require|create-project)\s+(?:--[^\s]+\s+)*([a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*)/i',
            $text,
            $matches,
        )) {
            foreach ($matches[1] as $name) {
                $claims[] = ['kind' => 'package', 'subject' => strtolower($name), 'document' => $label, 'raw' => $name];
            }
        }

        if (preg_match_all('/\b(?:php\s+)?artisan\s+([a-z][a-z0-9:_.-]*)/i', $text, $matches)) {
            foreach ($matches[1] as $command) {
                $claims[] = ['kind' => 'command', 'subject' => $command, 'document' => $label, 'raw' => $command];
            }
        }

        foreach ($this->attributeSpans($this->attributeView($label, $text)) as $span) {
            if (! $this->isConstantAttributeExpression($span)) {
                $unparsed[] = ['document' => $label, 'text' => $span];

                continue;
            }

            $claims[] = ['kind' => 'attribute', 'subject' => $this->normalise($span), 'document' => $label, 'raw' => $span];
        }

        return $claims;
    }

    /**
     * The text an attribute scan runs over. For a JS/TS document that is the file's string literals joined
     * in source order — a PHP sample in an island is fragmented across them, and a raw balanced-bracket
     * scan matches JSX instead of the sample.
     */
    protected function attributeView(string $label, string $text): string
    {
        $extension = strtolower(pathinfo($label, PATHINFO_EXTENSION));

        if (! in_array($extension, self::LITERAL_EXTENSIONS, true)) {
            return $text;
        }

        return implode("\n", $this->stringLiterals($text));
    }

    /**
     * The string literals of a JS/TS source, in source order, MINUS the ones sitting in non-final
     * call-argument position.
     *
     * That exclusion is the whole difference between a reconstruction and noise, and it was measured at
     * `~/Herd/splicewire` rather than reasoned: the islands wrap every fragment as
     * `{tok('attr', '#[ParticleResource(')}`, so a naive join interleaves the token-class label with the
     * code and yields `#[ParticleResource( attr key: 'articles' … attr )]` — a PHP syntax error, reported
     * against a sample that is correct. A literal immediately followed by `,` and another string is a
     * label or selector, never the content; the content is the last argument.
     *
     * @return list<string>
     */
    protected function stringLiterals(string $text): array
    {
        $literals = [];
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $char = $text[$i];

            if ($char !== "'" && $char !== '"' && $char !== '`') {
                $i++;

                continue;
            }

            $quote = $char;
            $buffer = '';
            $i++;

            while ($i < $length) {
                $c = $text[$i];

                if ($c === '\\' && $i + 1 < $length) {
                    $next = $text[$i + 1];
                    $buffer .= match ($next) {
                        'n' => "\n",
                        't' => "\t",
                        'r' => "\r",
                        default => $next,
                    };
                    $i += 2;

                    continue;
                }

                if ($c === $quote) {
                    $i++;

                    break;
                }

                $buffer .= $c;
                $i++;
            }

            $after = $i;

            while ($after < $length && ctype_space($text[$after])) {
                $after++;
            }

            if ($after < $length && $text[$after] === ',') {
                $after++;

                while ($after < $length && ctype_space($text[$after])) {
                    $after++;
                }

                if ($after < $length && in_array($text[$after], ["'", '"', '`'], true)) {
                    continue;
                }
            }

            $literals[] = $buffer;
        }

        return $literals;
    }

    /**
     * Every `#[ … ]` span, matched by BRACKET BALANCE rather than by a regex — an attribute's arguments can
     * contain `[`, `]` and nested parentheses, and a lazy `#\[(.*?)\]` truncates at the first array literal.
     *
     * @return list<string>
     */
    protected function attributeSpans(string $text): array
    {
        $spans = [];
        $offset = 0;

        while (($start = strpos($text, '#[', $offset)) !== false) {
            $depth = 0;
            $end = null;

            for ($i = $start + 1, $length = strlen($text); $i < $length; $i++) {
                $char = $text[$i];

                if ($char === '[' || $char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ']') {
                    $depth--;

                    if ($depth === 0) {
                        $end = $i;

                        break;
                    }
                }
            }

            if ($end === null) {
                // An unterminated `#[` is a blind spot, not a claim: report the tail so it is counted.
                $spans[] = substr($text, $start, 200);

                break;
            }

            $spans[] = substr($text, $start, $end - $start + 1);
            $offset = $end + 1;
        }

        return $spans;
    }

    protected function normalise(string $span): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $span));
    }

    /**
     * Is this span a constant expression — i.e. safe to instantiate, and shaped like a real attribute?
     *
     * The scan is a token walk rather than a parse, so it needs no parser dependency and cannot be fooled
     * by a `#[` inside JSX: anything carrying a variable, a `{`, a `new`, or a call in argument position is
     * refused. A refusal is COUNTED, never warned about — a span this rejects is far more likely to be
     * markup that happens to start with `#[` than a defective sample.
     */
    protected function isConstantAttributeExpression(string $span): bool
    {
        $tokens = @token_get_all('<?php '.$span.' class __BeamMarketingProbe {}');

        if ($tokens === false || $tokens === []) {
            return false;
        }

        $allowed = [
            T_ATTRIBUTE, T_WHITESPACE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE, T_NS_SEPARATOR, T_DOUBLE_COLON, T_CLASS, T_LNUMBER, T_DNUMBER,
            T_CONSTANT_ENCAPSED_STRING, T_ARRAY, T_DOUBLE_ARROW, T_COMMENT, T_DOC_COMMENT, T_NEW,
        ];
        $allowedChars = ['(', ')', '[', ']', ',', ':', '-', '+', '.', '|', '?'];

        $started = false;
        $depth = 0;
        $names = [];
        $previous = null;
        $previousWasName = false;
        $previousNameWasHead = false;

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_OPEN_TAG) {
                continue;
            }

            if (! $started) {
                if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                    $started = true;
                    $depth = 1;
                    $previous = 'head';

                    continue;
                }

                // Anything before the attribute means the span does not begin with one.
                return false;
            }

            if (is_string($token)) {
                if (! in_array($token, $allowedChars, true)) {
                    return false;
                }

                if ($token === '(' || $token === '[') {
                    // A name in ARGUMENT position followed by `(` is a function call, not a constant
                    // expression — the one shape that would let a doctor run execute copy off a web page.
                    if ($previousWasName && ! $previousNameWasHead) {
                        return false;
                    }

                    $depth++;
                } elseif ($token === ')' || $token === ']') {
                    $depth--;

                    if ($depth === 0) {
                        return $names !== [];
                    }
                }

                $previous = $token === ',' && $depth === 1 ? 'head' : $token;
                $previousWasName = false;
                $previousNameWasHead = false;

                continue;
            }

            if (! in_array($token[0], $allowed, true)) {
                return false;
            }

            // `new` is legal in an attribute argument, but this audit will not construct arbitrary objects
            // on a doctor run; a sample needing one is a blind spot, not a claim.
            if ($token[0] === T_NEW) {
                return false;
            }

            $isName = in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
            $isHeadName = $isName && $previous === 'head' && $depth === 1;

            if ($isHeadName) {
                $names[] = $token[1];
            }

            if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                $previous = $token[0];
                $previousWasName = $isName;
                $previousNameWasHead = $isHeadName;
            }
        }

        return false;
    }

    /** @param array{kind: string, subject: string, document: string, raw: string} $claim */
    protected function verifyPackage(array $claim): ?Finding
    {
        $installed = $this->installedPackages();

        if ($installed === null) {
            return null;
        }

        if (in_array($claim['subject'], $installed, true)) {
            return null;
        }

        return Finding::warn(self::CHECK_PACKAGE, sprintf(
            '%s names package [%s], which resolves to nothing in this estate\'s manifest (%d package%s in '
            .'`vendor/composer/installed.json` plus this root\'s own name). A visitor who copy-pastes that '
            .'line gets a composer failure. Spell the package the estate declares, or say in the copy that '
            .'it is not published yet.',
            $claim['document'],
            $claim['raw'],
            count($installed),
            count($installed) === 1 ? '' : 's',
        ));
    }

    /** @param array{kind: string, subject: string, document: string, raw: string} $claim */
    protected function verifyCommand(array $claim): ?Finding
    {
        $registered = $this->registeredCommands();

        if ($registered === null) {
            return null;
        }

        if (in_array($claim['subject'], $registered, true)) {
            return null;
        }

        return Finding::warn(self::CHECK_COMMAND, sprintf(
            '%s prints `php artisan %s`, which is not a command registered in this booted host (%d '
            .'registered). This is the defect that shipped: `make:particle-resource` for '
            .'`splicewire:beam:make:particle-resource`, in three places, and a copy-paste answered "command '
            .'is not defined."',
            $claim['document'],
            $claim['raw'],
            count($registered),
        ));
    }

    /** @param array{kind: string, subject: string, document: string, raw: string} $claim */
    protected function verifyAttribute(array $claim): ?Finding
    {
        $names = $this->attributeNames($claim['raw']);
        $imports = [];

        foreach ($names as $name) {
            $resolved = $this->resolveAttributeClass($name);

            if ($resolved === null) {
                return Finding::warn(self::CHECK_ATTRIBUTE, sprintf(
                    '%s shows `%s`, and [%s] resolves to no attribute class in this estate — not '
                    .'fully-qualified, and not in any of the %d configured namespaces. A sample naming an '
                    .'attribute nobody ships is the `BeamSchema` defect.',
                    $claim['document'],
                    $claim['subject'],
                    $name,
                    count($this->attributeNamespaces),
                ));
            }

            $imports[$name] = $resolved;
        }

        // A BARE `#[ParticleResource]` in prose is a MENTION, not a sample: it makes no claim about
        // arguments, so instantiating it manufactures "Too few arguments" against copy that is correct.
        // Measured at ~/Herd/splicewire, where three doc entries name the attribute in a sentence. The
        // existence check above still runs — that is the `BeamSchema` defect, and a mention can commit it.
        if (! str_contains($claim['raw'], '(')) {
            return null;
        }

        $error = $this->instantiate($claim['raw'], $imports);

        if ($error === null) {
            return null;
        }

        return Finding::warn(self::CHECK_ATTRIBUTE, sprintf(
            '%s shows `%s`, which does not survive instantiation: %s. A reader who pastes it gets that same '
            .'error — this is the shape a name-resolver passes and a constructor rejects (ticket 06 #3: '
            .'`key:` and `backing:` are `#[ParticleResource]`\'s required parameters, and the shipped sample '
            .'declared neither).',
            $claim['document'],
            $claim['subject'],
            $error,
        ));
    }

    /** @return list<string> */
    protected function attributeNames(string $span): array
    {
        $tokens = @token_get_all('<?php '.$span.' class __BeamMarketingProbe {}');
        $names = [];
        $depth = 0;
        $head = false;

        foreach ($tokens ?: [] as $token) {
            if (is_array($token)) {
                if ($token[0] === T_ATTRIBUTE) {
                    $depth = 1;
                    $head = true;

                    continue;
                }

                if ($head && $depth === 1 && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $names[] = $token[1];
                    $head = false;
                }

                continue;
            }

            if ($token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === ')' || $token === ']') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($token === ',' && $depth === 1) {
                $head = true;
            }
        }

        return $names;
    }

    protected function resolveAttributeClass(string $name): ?string
    {
        if (str_contains($name, '\\')) {
            $fqcn = ltrim($name, '\\');

            return class_exists($fqcn) ? $fqcn : null;
        }

        $fallback = null;

        foreach ($this->attributeNamespaces as $namespace) {
            $fqcn = trim($namespace, '\\').'\\'.$name;

            if (! class_exists($fqcn)) {
                continue;
            }

            if ((new ReflectionClass($fqcn))->getAttributes(Attribute::class) !== []) {
                return $fqcn;
            }

            // A same-named class that is not marked `#[Attribute]` is kept only as a last resort, so the
            // finding can still say what it found rather than "resolves to nothing".
            $fallback ??= $fqcn;
        }

        return $fallback;
    }

    /**
     * Instantiate the attribute group for real, and return the error message if PHP refuses.
     *
     * Reflection is the only instrument that can see a missing required parameter: `class_exists()` passes,
     * a grep passes, and the reader's `ArgumentCountError` is the first honest reading. The probe file is
     * keyed by PID (AGENTS.md: a fixed scratch name collides across concurrent sessions) and unlinked in a
     * `finally`.
     *
     * @param  array<string, class-string>  $imports  short name => resolved FQCN
     */
    protected function instantiate(string $span, array $imports): ?string
    {
        // A sample does not say where it sits. `#[Required, Max(120)]` annotates a promoted constructor
        // PARAMETER in the copy it came from; probed only as a class attribute it answers "cannot target
        // class", which is a fact about the probe and not about the copy. Try each placement and take a
        // success anywhere — the errors this audit is for (a missing required argument, an attribute
        // nobody ships) fail identically at all three.
        $errors = [];

        foreach (['class', 'property', 'parameter'] as $placement) {
            $error = $this->instantiateAt($span, $imports, $placement);

            if ($error === null) {
                return null;
            }

            if (! str_contains($error, 'cannot target')) {
                $errors[] = $error;
            }
        }

        return $errors[0] ?? 'the attribute targets none of class, property or parameter';
    }

    /** @param  array<string, class-string>  $imports */
    protected function instantiateAt(string $span, array $imports, string $placement): ?string
    {
        $id = 'P'.getmypid().'_'.bin2hex(random_bytes(6));
        $namespace = 'Splicewire\\Beam\\Doctor\\MarketingProbe\\'.$id;
        $file = rtrim(sys_get_temp_dir(), '/').'/beam-marketing-probe-'.$id.'.php';

        $use = '';

        foreach ($imports as $short => $fqcn) {
            if (! str_contains($short, '\\')) {
                $use .= 'use '.$fqcn." as {$short};\n";
            }
        }

        $body = match ($placement) {
            'property' => "class Probe\n{\n{$span}\npublic \$slot;\n}\n",
            'parameter' => "class Probe\n{\npublic function __construct({$span} public string \$slot = '') {}\n}\n",
            default => "{$span}\nclass Probe {}\n",
        };

        $source = "<?php\n\nnamespace {$namespace};\n\n{$use}\n{$body}";

        try {
            if (@file_put_contents($file, $source) === false) {
                return null;
            }

            require $file;

            $reflection = new ReflectionClass($namespace.'\\Probe');

            $attributes = match ($placement) {
                'property' => $reflection->getProperty('slot')->getAttributes(),
                'parameter' => $reflection->getConstructor()?->getParameters()[0]->getAttributes() ?? [],
                default => $reflection->getAttributes(),
            };

            foreach ($attributes as $attribute) {
                $attribute->newInstance();
            }

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            @unlink($file);
        }
    }

    /** @return list<string>|null */
    protected function installedPackages(): ?array
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        $path = $this->installedJsonPath;

        if ($path === null || ! is_file($path)) {
            // No manifest, no reading. A package claim is not verifiable here, and saying nothing is
            // honest where a pass would not be.
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        $packages = $decoded['packages'] ?? (is_array($decoded) ? $decoded : []);
        $names = [];

        foreach ((array) $packages as $package) {
            if (isset($package['name'])) {
                $names[] = strtolower((string) $package['name']);
            }
        }

        if ($this->rootComposerPath !== null && is_file($this->rootComposerPath)) {
            $root = json_decode((string) @file_get_contents($this->rootComposerPath), true);

            if (isset($root['name'])) {
                $names[] = strtolower((string) $root['name']);
            }
        }

        return $this->installed = array_values(array_unique($names));
    }

    /** @return list<string>|null */
    protected function registeredCommands(): ?array
    {
        if ($this->commands !== null) {
            return $this->commands;
        }

        try {
            return array_values(array_keys(app(ConsoleKernel::class)->all()));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, array{kind: string, subject: string, document: string, raw: string}> $claims */
    protected function tally(array $claims): string
    {
        $counts = ['package' => 0, 'command' => 0, 'attribute' => 0];

        foreach ($claims as $claim) {
            $counts[$claim['kind']]++;
        }

        return sprintf(
            '%d composer package name%s, %d artisan command name%s, %d attribute sample%s instantiated by reflection',
            $counts['package'], $counts['package'] === 1 ? '' : 's',
            $counts['command'], $counts['command'] === 1 ? '' : 's',
            $counts['attribute'], $counts['attribute'] === 1 ? '' : 's',
        );
    }

    /**
     * @param  list<string>  $unreadable
     * @param  list<array{document: string, text: string}>  $unparsed
     */
    protected function blindSpot(array $unreadable, array $unparsed): string
    {
        if ($unreadable === [] && $unparsed === []) {
            return '';
        }

        $parts = [];

        if ($unreadable !== []) {
            $parts[] = sprintf(
                '%d source%s could not be read and %s NOT inspected (%s)',
                count($unreadable),
                count($unreadable) === 1 ? '' : 's',
                count($unreadable) === 1 ? 'was' : 'were',
                implode(', ', array_slice($unreadable, 0, 5)),
            );
        }

        if ($unparsed !== []) {
            $documents = array_values(array_unique(array_column($unparsed, 'document')));

            $parts[] = sprintf(
                '%d `#[…]` span%s in %d document%s %s not a constant expression, so %s NOT instantiated (%s)',
                count($unparsed),
                count($unparsed) === 1 ? '' : 's',
                count($documents),
                count($documents) === 1 ? '' : 's',
                count($unparsed) === 1 ? 'was' : 'were',
                count($unparsed) === 1 ? 'it was' : 'they were',
                implode(', ', array_slice($documents, 0, 5)),
            );
        }

        return ' Did not look: '.implode('; ', $parts).'.';
    }
}
