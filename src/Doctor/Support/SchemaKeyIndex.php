<?php

namespace Splicewire\Beam\Doctor\Support;

use Splicewire\Beam\Doctor\KeyTypeConformanceAudit;

/**
 * The static key-type index {@see KeyTypeConformanceAudit} reads: for every table a migration creates,
 * what type its primary key is declared as; for every Eloquent model, what key type it declares; and
 * every foreign key column with the table it points at.
 *
 * Built by reading source, never a database — so it works in a host that has never migrated, and in CI
 * with no connection at all. That is the whole reason this is a doctor audit rather than a
 * `Schema::getColumnType()` inspection: the defect it hunts is authored in a migration and a model, and
 * both exist long before any database does.
 *
 * ## Scope differs from the facade regime's, deliberately
 * {@see FacadeConformanceScope} excludes dated `database/migrations/*.php` because those are published
 * copies and a fix belongs in the `.php.stub` upstream. **Here they are included, because they are the
 * schema this host actually builds.** A published migration is exactly where a wrong key type does its
 * damage, and telling a host "your `users` table is bigint" is actionable even though the template it
 * came from lives elsewhere. Same estate, opposite rule, for the same reason: report where the fix is.
 *
 * ## The last declaration wins, not the create (beam-facade ticket 144)
 * A create is the *first* statement about a column, not the last. `~/Herd/fable-legacy` declares
 * `model_id` as `unsignedBigInteger` in `create_permission_tables` and then, eight months later, drops
 * and re-adds it as `uuid` in `fix_permission_tables_for_uuid`; the live database agrees with the fix.
 * Reading only creates reported a schema gap that does not exist — a false positive under three shipped
 * predicates, and the one thing that reliably gets a check switched off.
 *
 * So {@see indexAlterations()} runs a second pass over post-create statements, and the last declaration
 * of a tracked column wins, ordered by the migration filename's timestamp and then by byte offset within
 * the file. Three constraints make that safe rather than a half-replay:
 *
 *  - **`up()` only.** Every one of these migrations has a `down()` that restores the *old* type verbatim,
 *    so a whole-file read would reverse the very fix it was trying to see.
 *  - **Tracked columns only.** An alteration amends a table/column this index already took from a create;
 *    it never invents a table, a morph key or a foreign key that no create declared.
 *  - **What it cannot read, it un-knows.** A raw `ALTER TABLE` this reader cannot classify — a
 *    `RENAME COLUMN` that swaps one column's identity for another's (`~/Herd/prahsys-gateway` migrates
 *    the `organizations` primary key exactly that way), a `TYPE` it has no mapping for — sets the tracked
 *    type to `null`, which every predicate already treats as *skip, never guess*. That is deliberate: the
 *    alternative is to keep trusting a create the same file just contradicted, which trades this ticket's
 *    false positive for a **false negative**, and a check that stays quiet about a real defect is worse
 *    than one that nags. Those un-knowings are counted by {@see unreadableAlterationCount()} and reported
 *    in the audit's Pass line, like every other skip in this regime.
 *
 * This is emphatically not a migration evaluator. It reads declarations; it does not execute them,
 * resolve a config value, follow a conditional, or know whether a migration ever ran.
 */
class SchemaKeyIndex
{
    /** Column-declaration forms that mean "integer primary key". */
    public const INT_PK_FORMS = ['id', 'bigIncrements', 'increments', 'mediumIncrements', 'smallIncrements', 'tinyIncrements'];

    /**
     * Column-declaration forms a morph key can take, mapped to the key type they compare as. An
     * unlisted form indexes as `null` — unknown, therefore skipped, never guessed.
     */
    public const MORPH_KEY_TYPES = [
        'uuid' => 'uuid',
        'foreignUuid' => 'uuid',
        'ulid' => 'ulid',
        'foreignUlid' => 'ulid',
        'unsignedBigInteger' => 'int',
        'bigInteger' => 'int',
        'unsignedInteger' => 'int',
        'integer' => 'int',
        'foreignId' => 'int',
        'string' => 'string',
        'char' => 'string',
    ];

    /** @var array<string, array{key_type: string|null, source: string, inferred_name: bool, models: list<array{class: string, fqcn: string, key_type: string|null}>}> */
    private array $tables = [];

    /** @var list<array{table: string, column: string, type: string|null, source: string, references: string}> */
    private array $foreignKeys = [];

    /**
     * Morph-key columns — the `<name>_id` half of a polymorphic pair, indexed with the type the migration
     * declares for it.
     *
     * Separate from {@see $foreignKeys} because a morph key **is not a foreign key**: it points at no
     * table declaratively, so there is nothing for a foreign-key walk to follow. That is exactly why the
     * mismatch it can carry is invisible — see `morph-key-holder-disagreement` in
     * {@see KeyTypeConformanceAudit}, which supplies the holder this index cannot infer.
     *
     * @var list<array{table: string, column: string, type: string|null, source: string}>
     */
    private array $morphKeys = [];

    /**
     * SQL type names a raw `ALTER TABLE ... TYPE <t>` can name, mapped to the key type they compare as.
     * Unlisted means unreadable, which un-knows the column rather than guessing at it.
     */
    public const SQL_KEY_TYPES = [
        'uuid' => 'uuid',
        'ulid' => 'ulid',
        'char' => 'string',
        'varchar' => 'string',
        'text' => 'string',
        'citext' => 'string',
        'bigint' => 'int',
        'int8' => 'int',
        'integer' => 'int',
        'int' => 'int',
        'int4' => 'int',
        'smallint' => 'int',
        'int2' => 'int',
        'serial' => 'int',
        'bigserial' => 'int',
    ];

    /** @var list<array{class: string, fqcn: string, key_type: string|null, table: string|null}> */
    private array $models = [];

    /**
     * Post-create declarations about a column, in the order they would run.
     *
     * @var list<array{order: array{string, string, int}, table: string, column: string, type: string|null, source: string}>
     */
    private array $alterations = [];

    /**
     * Where each tracked column was **created**, in the same order key the alterations carry — so an
     * alteration only counts when it genuinely comes after the create it amends. Without this a migration
     * dated *before* a create would overrule it, which is not what "last declaration wins" means.
     *
     * @var array<string, array{string, string, int}>
     */
    private array $declaredAt = [];

    private int $unresolvedModels = 0;

    private int $unreadableAlterations = 0;

    private int $unparsedCreates = 0;

    private int $unreferencedForeignKeyColumns = 0;

    /** @var array<string, int> */
    private array $unparsedCreateFiles = [];

    /** @param  list<string>  $roots */
    public function __construct(public array $roots)
    {
        $this->build();
    }

    /**
     * Host code plus every family package it composes through the overlay — the same reach as
     * {@see FacadeConformanceScope::forApp()}, and for the same reason: the estate's models live in
     * packages, so an `app/`-only scan reports a comfortable zero from inside every host.
     *
     * @param  list<string>|null  $roots
     */
    public static function forApp(?array $roots = null): self
    {
        $roots ??= array_values(array_filter([
            base_path('app'),
            base_path('src'),
            base_path('database'),
            ...FacadeConformanceScope::authorablePackageRoots(),
        ], 'is_dir'));

        return new self(array_values($roots));
    }

    /** @return array<string, array{key_type: string|null, source: string, inferred_name: bool, models: list<array{class: string, fqcn: string, key_type: string|null}>}> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return list<array{table: string, column: string, type: string|null, source: string, references: string}> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    /** @return list<array{table: string, column: string, type: string|null, source: string}> */
    public function morphKeys(): array
    {
        return $this->morphKeys;
    }

    public function keyTypeOf(string $table): ?string
    {
        return $this->tables[$table]['key_type'] ?? null;
    }

    /**
     * The **first-party** models this index found bound to a table — first-party because the walk excludes
     * `vendor/`, so anything here is source somebody in the estate can edit.
     *
     * @return list<array{class: string, fqcn: string, key_type: string|null}>
     */
    public function modelsFor(string $table): array
    {
        return $this->tables[$table]['models'] ?? [];
    }

    /**
     * The key type an **already-loaded class** declares, read by reflection — the counterpart to
     * {@see indexModel()} for a model this index's walk can never see because it lives in `vendor/`.
     *
     * Deliberately the same three rules in the same order, so a third-party model and a first-party one
     * are judged identically: `HasUuids`/`HasUlids` (checked on the class, its parents and their traits,
     * because those traits override `getKeyType()` rather than setting the property), then `$keyType`,
     * then Eloquent's default. Returns null when the class is not loadable — this reads no files and
     * autoloads nothing beyond what `class_exists()` does, so it stays a static check with no database
     * and no migration required, and a null is a **skip that gets counted**, never a silent pass.
     */
    public static function keyTypeOfClass(string $class): ?string
    {
        if ($class === '' || ! class_exists($class)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($class);
        } catch (\ReflectionException) {
            return null;
        }

        $traits = static::traitsOf($reflection);

        if (isset($traits['Illuminate\Database\Eloquent\Concerns\HasUuids']) || isset($traits['Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids'])) {
            return 'uuid';
        }

        if (isset($traits['Illuminate\Database\Eloquent\Concerns\HasUlids'])) {
            return 'ulid';
        }

        $keyType = $reflection->getDefaultProperties()['keyType'] ?? null;

        if (is_string($keyType)) {
            return $keyType === 'int' ? 'int' : 'uuid';
        }

        return $reflection->isSubclassOf('Illuminate\Database\Eloquent\Model') ? 'int' : null;
    }

    /**
     * Every trait name on a class, its parents, and its traits' traits, as a set.
     *
     * Written out rather than reaching for `class_uses_recursive()`: this class is pure enough to be
     * exercised with no application booted, and that helper is a framework global.
     *
     * @return array<string, true>
     */
    private static function traitsOf(\ReflectionClass $reflection): array
    {
        $found = [];
        $queue = [];

        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            $queue = [...$queue, ...$class->getTraitNames()];
        }

        while ($queue !== []) {
            $trait = array_shift($queue);

            if (isset($found[$trait])) {
                continue;
            }

            $found[$trait] = true;

            try {
                $queue = [...$queue, ...(new \ReflectionClass($trait))->getTraitNames()];
            } catch (\ReflectionException) {
                // A trait that cannot be reflected contributes nothing; the rest of the set still stands.
            }
        }

        return $found;
    }

    public function tableCount(): int
    {
        return count($this->tables);
    }

    public function modelCount(): int
    {
        return count($this->models);
    }

    public function unresolvedModelCount(): int
    {
        return $this->unresolvedModels;
    }

    /**
     * Tracked columns a post-create statement altered in a way this reader could not classify, so their
     * type is now `null` — unknown, therefore skipped by every predicate. A counted skip, never a silent
     * pass and never a stale create reported as current.
     */
    public function unreadableAlterationCount(): int
    {
        return $this->unreadableAlterations;
    }

    /**
     * Create constructs whose table name this reader could not resolve statically — see
     * {@see countUnparsedCreates()}. The number of tables that exist in the estate's source and are not in
     * {@see tables()} for a reason nothing else reports.
     */
    public function unparsedCreateCount(): int
    {
        return $this->unparsedCreates;
    }

    /**
     * `foreignId`-family columns that state no target at all — see {@see statedReference()}. A counted
     * skip, because the alternative is a fabricated target, and the estate has a live case where the
     * fabricated one names a real but different table.
     */
    public function unreferencedForeignKeyColumnCount(): int
    {
        return $this->unreferencedForeignKeyColumns;
    }

    /**
     * The files those unparsed creates are in, `basename => count`, so the finding can name them rather
     * than only counting them.
     *
     * @return array<string, int>
     */
    public function unparsedCreateFiles(): array
    {
        return $this->unparsedCreateFiles;
    }

    private function build(): void
    {
        $files = [];

        foreach ($this->roots as $root) {
            foreach ($this->walk($root) as $path) {
                $files[realpath($path) ?: $path] = true;
            }
        }

        $files = array_keys($files);
        sort($files);

        // Models first, so a table's model list is complete before the migrations attach to it.
        foreach ($files as $file) {
            $source = @file_get_contents($file);

            if ($source === false) {
                continue;
            }

            $this->indexModel($source);
            $this->indexMigration($source, $file);
            $this->indexAlterations($source, $file);
        }

        $this->applyAlterations();
        $this->attachModels();
    }

    /**
     * A model's declared key type, or null when the class declares nothing about it.
     *
     * `HasUuids`/`HasUlids` are the estate's normal form; `$keyType` + `$incrementing = false` is the
     * longhand. **A model that declares nothing is reported as `int`, not as unknown** — that is not a
     * guess, it is Eloquent's documented default and the exact state that produced the `~/Herd/splicewire`
     * defect. Treating silence as "unknown" would have made this audit blind to the only instance of the
     * bug the estate actually had.
     */
    private function indexModel(string $source): void
    {
        if (! preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)\s+extends\s+(\w+)/m', $source, $class)) {
            return;
        }

        // Only Eloquent-ish classes. Authenticatable is Laravel's User base; the rest of the estate
        // extends Model or a family base class, which is caught by the `$table`/HasUuids evidence below.
        $isModel = preg_match('/\b(Model|Authenticatable|Pivot)\b/', $class[2]) === 1
            || str_contains($source, 'Illuminate\\Database\\Eloquent');

        if (! $isModel) {
            return;
        }

        $keyType = null;

        if (preg_match('/\buse\s+[^;]*\bHasUuids\b/', $source)) {
            $keyType = 'uuid';
        } elseif (preg_match('/\buse\s+[^;]*\bHasUlids\b/', $source)) {
            $keyType = 'ulid';
        } elseif (preg_match('/\$keyType\s*=\s*[\'"](\w+)[\'"]/', $source, $m)) {
            $keyType = $m[1] === 'string' ? 'uuid' : 'int';
        } elseif (preg_match('/class\s+\w+\s+extends\s+(?:Model|Authenticatable|Pivot)/', $source)) {
            // Declares nothing: Eloquent's default is an auto-incrementing integer key.
            $keyType = 'int';
        }

        $table = null;

        if (preg_match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $m)) {
            $table = $m[1];
        }

        // The namespace as well as the short name. A short name is enough to *report* a disagreement, but
        // not to answer "is THIS class the one bound here?" — `Splicewire\Tower\Models\Role` and
        // `Spatie\Permission\Models\Role` are different answers to that question and share a short name.
        $namespace = preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $source, $ns) ? $ns[1].'\\' : '';

        $this->models[] = [
            'class' => $class[1],
            'fqcn' => $namespace.$class[1],
            'key_type' => $keyType,
            'table' => $table,
        ];

        if ($table === null) {
            $this->unresolvedModels++;
        }
    }

    /**
     * Every `Schema::create(...)` block's primary-key form and foreign-key columns, for the two name forms
     * this index can read without executing anything.
     *
     * **Literal names** — `Schema::create('users', ...)` — are the common case and exact.
     *
     * **Config-array names** — `Schema::create($tableNames['roles'], ...)`, spatie's published shape and the
     * one `create_permission_tables` uses estate-wide — are indexed under the **array key**, flagged
     * `inferred_name`. This is not the `Beam::table(...)` guess the paragraph below rejects, and the
     * difference is worth stating because it looks superficially identical. A `Beam::table()` name is a
     * *prefix* concatenation whose result this index cannot spell, so inventing one invents a table that
     * does not exist. `$tableNames['roles']` is a *lookup by a literal key that is itself the conventional
     * table name*; the worst case when a host has renamed the table in config is that the finding names
     * `roles` while the table is called `acme_roles` — the defect it reports (a key type declared here
     * disagreeing with the model that reads it) is the same defect either way, because the key and the
     * table are one-to-one. A wrong label on a real finding, never a fabricated finding.
     *
     * Creates whose name is a method call or a concatenation are still simply not indexed — but they are
     * now **counted**; see {@see unparsedCreateCount()}.
     *
     * ## Two create verbs, because the estate is migrating off one of them (beam-facade 191)
     * `Schema::create` was the only verb this reader knew, and `rushing/laravel-schema-convergence`'s
     * `ConvergentTable::named(...)->define(...)` — which registry-kernel ticket 30's guard audit tells
     * every root to migrate *to*, and which **replaces** the `Schema::create` call rather than wrapping
     * it — was invisible. The consequence was not a missing finding here and there: **every root that
     * complied with the estate's own conformance program removed itself from this audit's reach, and the
     * most compliant root was the blindest.** `~/Herd/splicewire-app` indexed 28 tables against a
     * 164-table schema and reported agreement over the rest; `~/Herd/tower` indexed 5 of 29. Both read as
     * a clean Pass.
     *
     * The same two name forms are read for both verbs and for the same reasons — a literal, or a
     * config-array lookup whose key is itself the conventional table name. `ConvergentTable::named(Beam::table('x'))`
     * and the `$this->target()` accessor spellings stay unindexed, deliberately, and are what the unparsed
     * counter now reports.
     */
    private function indexMigration(string $source, string $file): void
    {
        $order = [$this->stampOf($file), $file, 0];
        $creates = [];

        // The two create verbs, spelled once. `Schema::create('t', function (...) {` and
        // `ConvergentTable::named('t')->define(function (...) {` both end their declaration block at the
        // first `\n<indent>})`, so one block terminator serves both.
        $verbs = [
            'Schema::create\(\s*',
            'ConvergentTable::named\(\s*',
        ];

        foreach ($verbs as $verb) {
            preg_match_all('/'.$verb.'[\'"]([^\'"]+)[\'"]\s*[,)].*?\n(\s*)\}\)/s', $source, $literal, PREG_SET_ORDER);

            foreach ($literal as $create) {
                $creates[] = [$create[0], $create[1], false];
            }

            preg_match_all('/'.$verb.'\$\w+\[\s*[\'"](\w+)[\'"]\s*\]\s*[,)].*?\n(\s*)\}\)/s', $source, $configured, PREG_SET_ORDER);

            foreach ($configured as $create) {
                $creates[] = [$create[0], $create[1], true];
            }
        }

        $this->countUnparsedCreates($source, $file, count($creates));

        foreach ($creates as $create) {
            [$block, $table, $inferredName] = $create;

            $keyType = null;

            if (preg_match('/->(?:uuid|foreignUuid)\(\s*[\'"]id[\'"]\s*\)\s*->primary\(\)/', $block)) {
                $keyType = 'uuid';
            } elseif (preg_match('/->ulid\(\s*[\'"]id[\'"]\s*\)\s*->primary\(\)/', $block)) {
                $keyType = 'ulid';
            } elseif (preg_match('/->('.implode('|', self::INT_PK_FORMS).')\(/', $block)) {
                $keyType = 'int';
            }

            if ($keyType !== null && ! isset($this->tables[$table])) {
                $this->declaredAt[$table.'.id'] = $order;
                $this->tables[$table] = [
                    'key_type' => $keyType,
                    'source' => basename($file),
                    'inferred_name' => $inferredName,
                    'models' => [],
                ];
            }

            $this->indexForeignKeys($block, $table, $file, $order);

            $this->indexMorphKeys($block, $table, $file);
        }
    }

    /**
     * The declared foreign keys in one create block, with the table each one actually points at.
     *
     * `foreignIdFor(Model::class)` stays omitted on purpose — it derives from the model, so it is right
     * exactly when the model is, which the primary-key predicate already governs.
     *
     * ## The target is READ where the migration states it, and only inferred where it does not
     * This used to derive every target by pluralising the column prefix, and never looked at the
     * `->constrained('users')` argument sitting three characters later on the same line. That is a blind
     * spot in the predicate's whole reason for existing: `~/Herd/numero` declares
     * `foreignId('purchaser_user_id')->constrained('users')` against a **uuid** `users.id`, and
     * `fk-target-disagreement` reported nothing, because it looked up `purchaser_users` — a table that
     * does not exist — and every predicate skips on an unknown target. Three such columns at one host,
     * invisible (beam-facade 191). An explicit `constrained('<literal>')` is a *declaration* of the
     * target; the pluralised prefix is a *guess*, and a declaration must win over a guess.
     *
     * A column whose target is stated explicitly no longer has to end in `_id` either — `signed_by`,
     * `invited_by` and `created_by` are how a third of the estate's holder references are spelled, and
     * the suffix requirement was only ever there to make the pluralise guess safe.
     *
     * ## And a column that constrains NOTHING is not a foreign key
     * Widening reach armed the old guess, which is the half of this repair that was found by doing it.
     * `~/Herd/splicewire-app` declares a bare `foreignId('subscription_id')` in
     * `create_stripe_subscription_items_table` whose own docblock says, in words, *"`subscription_id`
     * references stripe_subscriptions.id (bigint)"* — and the pluralised prefix names `subscriptions`,
     * a **different, uuid-keyed table in the same estate.** Under the old reach `subscriptions` was
     * never indexed and the guess died quietly on a null target; index it and the guess becomes a
     * confident, fabricated finding against a perfectly correct migration.
     *
     * So the reference is taken only where the migration **states** one: `constrained('t')`,
     * `references(...)->on('t')`, or a bare `constrained()` — which is not a guess but *Laravel's own*
     * derivation from the column name, i.e. the same rule the framework will apply at migrate time. A
     * column with no constraint clause at all states no target, is counted by
     * {@see unreferencedForeignKeyColumnCount()}, and is reported by nothing else. This is the audit's
     * own standing rule (*"a wrong label on a real finding, never a fabricated finding"*) applied to the
     * one place it was not.
     *
     * @param  array{string, string, int}  $order
     */
    private function indexForeignKeys(string $block, string $table, string $file, array $order): void
    {
        // The whole declaration statement, so the chained `->constrained(...)` is in reach. Terminated by
        // `;` rather than a newline because the estate spells long chains across several lines.
        if (! preg_match_all('/->(foreignId|foreignUuid|foreignUlid)\(\s*[\'"](\w+)[\'"]\s*\)([^;]*);/s', $block, $fks, PREG_SET_ORDER)) {
            return;
        }

        foreach ($fks as $fk) {
            [, $form, $column, $chain] = $fk;

            $references = $this->statedReference($column, $chain);

            if ($references === null) {
                $this->unreferencedForeignKeyColumns++;

                continue;
            }

            $this->declaredAt[$table.'.'.$column] ??= $order;
            $this->foreignKeys[] = [
                'table' => $table,
                'column' => $column,
                'type' => self::MORPH_KEY_TYPES[$form] ?? null,
                'source' => basename($file),
                'references' => $references,
            ];
        }
    }

    /**
     * The table a `foreignId`-family declaration **states** it points at, or null when it states none.
     *
     * Three stated forms, in precedence order — a named `constrained('t')`, an explicit
     * `references(...)->on('t')`, and a bare `constrained()`, whose target is Laravel's own
     * `Str::plural(Str::beforeLast($column, '_id'))`. The first two are declarations; the third is a
     * derivation the framework itself performs, which is why reproducing it here is reading rather than
     * guessing. Anything else — including a bare `foreignId('x_id')` with no constraint clause — states
     * nothing, and this returns null rather than inventing a table name.
     */
    private function statedReference(string $column, string $chain): ?string
    {
        if (preg_match('/->constrained\(\s*[\'"](\w+)[\'"]/', $chain, $named) === 1) {
            return $named[1];
        }

        if (preg_match('/->on\(\s*[\'"](\w+)[\'"]/', $chain, $on) === 1) {
            return $on[1];
        }

        if (preg_match('/->constrained\(\s*\)/', $chain) === 1 && str_ends_with($column, '_id')) {
            return $this->pluralize(substr($column, 0, -3));
        }

        return null;
    }

    /**
     * Create constructs this reader **saw the verb of and could not name a table from** — the audit's own
     * unknown blind spot, counted instead of vanishing.
     *
     * The Pass line already counts three kinds of skip (an unresolvable model table, an unloadable
     * third-party binding, an unreadable ALTER) and argues in the audit's docblock that *"no findings"
     * and "could not look" are different facts*. It did not count this one. A `Schema::create(Beam::table('x'), …)`
     * or a `ConvergentTable::named($this->target())` simply never entered `$this->tables`, so the table
     * was not checked, was not reported as unchecked, and the Pass line's *"N table(s) checked"* read as
     * thorough precisely where the reader was blind. **An instrument that enumerates its known blind
     * spots and not its unknown ones is at its most misleading when it is most confident** — this counter
     * is the durable half of beam-facade 191, because the next unreadable spelling will not be
     * `ConvergentTable`.
     *
     * Counted per **construct**, not per file: one migration can create three tables and lose one.
     *
     * Scoped to files on a `migrations` path, which is not tidiness — measured 2026-08-29 at
     * `~/Herd/splicewire-app`, an unscoped count read 124 across 98 files and **34 of them were the
     * estate's own scanners** (`MigrationTableScanner`, `SchemaCreateScanner`, `MigrationOrderingAudit`,
     * and this very file), whose regex literals spell the verb without creating anything. That is the
     * same false positive `UnmappedConvergentTypeAudit`'s substring marker takes on a docblock, and a
     * blind-spot counter that cries about the instrument reading itself is a counter nobody reads.
     */
    private function countUnparsedCreates(string $source, string $file, int $parsed): void
    {
        if (! str_contains(str_replace('\\', '/', $file), '/migrations/')) {
            return;
        }

        $constructs = preg_match_all('/(?:Schema::create|ConvergentTable::named)\s*\(/', $source);

        if ($constructs <= $parsed) {
            return;
        }

        $this->unparsedCreates += $constructs - $parsed;
        $this->unparsedCreateFiles[basename($file)] = ($this->unparsedCreateFiles[basename($file)] ?? 0) + ($constructs - $parsed);
    }

    /**
     * The morph-key columns one `Schema::create` block declares.
     *
     * A morph key is only recognised **in pairs** — the block must declare a `<prefix>_type` string
     * column, and that is what names the prefix. Without the `_type` sibling there is no polymorphic
     * relation, only an ordinary column that happens to end in `_id`, and reporting one of those would
     * be a fabricated finding.
     *
     * Two spellings for the id half, because the estate uses both. The **literal** `<prefix>_id` is the
     * ordinary case. The **config-array** `$columnNames['<prefix>_morph_key']` is spatie's published
     * shape and the one `create_permission_tables` uses estate-wide — indexed under the conventional
     * column name for the same reason {@see indexMigration()} indexes a config-array *table* name under
     * its key: the lookup key is itself the conventional name, so the worst case is a renamed column
     * carrying a slightly wrong label on a real finding, never a finding about a column that does not
     * exist.
     *
     * Laravel's `morphs()`/`uuidMorphs()`/`ulidMorphs()` helpers declare both halves at once and are read
     * directly, since their type is fixed by which helper was called.
     */
    private function indexMorphKeys(string $block, string $table, string $file): void
    {
        $record = function (string $column, ?string $type) use ($table, $file): void {
            $this->declaredAt[$table.'.'.$column] ??= [$this->stampOf($file), $file, 0];
            $this->morphKeys[] = [
                'table' => $table,
                'column' => $column,
                'type' => $type,
                'source' => basename($file),
            ];
        };

        if (preg_match_all('/->(morphs|uuidMorphs|ulidMorphs)\(\s*[\'"](\w+)[\'"]\s*\)/', $block, $helpers, PREG_SET_ORDER)) {
            foreach ($helpers as $helper) {
                $record($helper[2].'_id', match ($helper[1]) {
                    'uuidMorphs' => 'uuid',
                    'ulidMorphs' => 'ulid',
                    default => 'int',
                });
            }
        }

        if (! preg_match_all('/->string\(\s*[\'"](\w+)_type[\'"]\s*\)/', $block, $types, PREG_SET_ORDER)) {
            return;
        }

        foreach ($types as $type) {
            $prefix = preg_quote($type[1], '/');
            $name = '(?:[\'"]'.$prefix.'_id[\'"]|\$\w+\[\s*[\'"]'.$prefix.'_morph_key[\'"]\s*\])';

            if (! preg_match('/->(\w+)\(\s*'.$name.'\s*\)/', $block, $decl)) {
                continue;
            }

            $record($type[1].'_id', self::MORPH_KEY_TYPES[$decl[1]] ?? null);
        }
    }

    /**
     * Every post-create statement in a migration's `up()` that re-declares a column's type — the second
     * pass the class docblock's "last declaration wins" section describes.
     *
     * Scoped to `up()` on purpose. `down()` restores the old type verbatim in every instance the estate
     * has (`fix_permission_tables_for_uuid` re-adds `unsignedBigInteger` there), so reading a whole file
     * would cancel out the fix and leave the reader exactly as wrong as it was before, but for a subtler
     * reason.
     *
     * Two statement families, because the estate uses both and one of them is where the reader's honest
     * limit sits. **Blueprint** re-declarations inside `Schema::table(...)` — with or without `->change()`,
     * since a drop-then-re-add says the same thing — are read the same way a create's are. **Raw
     * `ALTER TABLE`** is read only for the shapes that state a type outright (`ALTER COLUMN … TYPE`,
     * `MODIFY`, `CHANGE`); a `DROP COLUMN` or a `RENAME COLUMN` says the column this index tracked is
     * gone or is now some other column's data, so it records `null` — unknown — and a later Blueprint
     * re-add in the same file simply wins over it by byte offset, which is exactly what fable-legacy's
     * drop/re-add pair needs. Everything else raw DDL does (constraints, indexes, `SET NOT NULL`, adding
     * a column this index never tracked) states nothing about a tracked type and is ignored.
     */
    private function indexAlterations(string $source, string $file): void
    {
        $start = strpos($source, 'function up(');

        if ($start === false) {
            return;
        }

        $end = strpos($source, 'function down(', $start);
        $up = substr($source, $start, $end === false ? null : $end - $start);
        $stamp = $this->stampOf($file);

        $record = function (string $table, string $column, ?string $type, int $offset) use ($file, $stamp): void {
            $this->alterations[] = [
                'order' => [$stamp, $file, $offset],
                'table' => $table,
                'column' => $column,
                'type' => $type,
                'source' => basename($file),
            ];
        };

        if (preg_match_all('/Schema::table\(\s*(?:[\'"]([^\'"]+)[\'"]|\$\w+\[\s*[\'"](\w+)[\'"]\s*\])\s*,.*?\n(\s*)\}\)/s', $up, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($blocks as $block) {
                $table = $block[1][0] !== '' ? $block[1][0] : ($block[2] ?? ['', -1])[0];

                if ($table === '') {
                    continue;
                }

                $base = $block[0][1];

                if (! preg_match_all('/->(\w+)\(\s*(?:[\'"](\w+)[\'"]|\$\w+\[\s*[\'"](\w+)[\'"]\s*\])/', $block[0][0], $columns, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                foreach ($columns as $column) {
                    $type = self::MORPH_KEY_TYPES[$column[1][0]] ?? (in_array($column[1][0], self::INT_PK_FORMS, true) ? 'int' : null);

                    if ($type === null) {
                        continue;
                    }

                    $literal = ($column[2] ?? ['', -1])[0];
                    $configured = ($column[3] ?? ['', -1])[0];
                    $name = $literal !== '' ? $literal : str_replace('_morph_key', '_id', $configured);

                    if ($name === '') {
                        continue;
                    }

                    $record($table, $name, $type, $base + $column[0][1]);
                }
            }
        }

        if (! preg_match_all('/ALTER\s+TABLE\s+[`"\']?(\w+)[`"\']?\s+([^;\'"`]*)/i', $up, $raw, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return;
        }

        foreach ($raw as $statement) {
            $table = $statement[1][0];
            $rest = trim($statement[2][0]);
            $offset = $statement[0][1];

            if (preg_match('/^ALTER\s+COLUMN\s+[`"]?(\w+)[`"]?\s+(?:SET\s+DATA\s+)?TYPE\s+(\w+)/i', $rest, $m)) {
                $record($table, $m[1], self::SQL_KEY_TYPES[strtolower($m[2])] ?? null, $offset);
            } elseif (preg_match('/^MODIFY(?:\s+COLUMN)?\s+[`"]?(\w+)[`"]?\s+(\w+)/i', $rest, $m)) {
                $record($table, $m[1], self::SQL_KEY_TYPES[strtolower($m[2])] ?? null, $offset);
            } elseif (preg_match('/^CHANGE(?:\s+COLUMN)?\s+[`"]?\w+[`"]?\s+[`"]?(\w+)[`"]?\s+(\w+)/i', $rest, $m)) {
                $record($table, $m[1], self::SQL_KEY_TYPES[strtolower($m[2])] ?? null, $offset);
            } elseif (preg_match('/^DROP\s+COLUMN\s+(?:IF\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i', $rest, $m)) {
                $record($table, $m[1], null, $offset);
            } elseif (preg_match('/^RENAME\s+COLUMN\s+[`"]?(\w+)[`"]?\s+TO\s+[`"]?(\w+)[`"]?/i', $rest, $m)) {
                $record($table, $m[1], null, $offset);
                $record($table, $m[2], null, $offset);
            }
        }
    }

    /**
     * The migration filename's timestamp prefix, which is the order the framework itself runs them in.
     * A file without one — a `.php.stub`, the authored origin of a published copy — sorts first, so a
     * dated migration at this host always wins over the template it came from.
     */
    private function stampOf(string $file): string
    {
        return preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})/', basename($file), $m) ? $m[1] : '';
    }

    /**
     * Fold the alterations onto the creates: last declaration wins, and only for a table/column a create
     * already put in the index.
     *
     * Nothing here can add a table, a morph key or a foreign key — an alteration is an amendment to
     * something already indexed, never a fresh declaration. That is what keeps the second pass from being
     * a migration replay: the population it can affect is fixed before it runs.
     */
    private function applyAlterations(): void
    {
        if ($this->alterations === []) {
            return;
        }

        usort($this->alterations, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $before = [];

        foreach ($this->alterations as $alteration) {
            $table = $alteration['table'];
            $column = $alteration['column'];
            $key = $table.'.'.$column;

            if (! isset($this->declaredAt[$key]) || $alteration['order'] <= $this->declaredAt[$key]) {
                continue;
            }

            if ($column === 'id' && isset($this->tables[$table])) {
                $before[$key] = array_key_exists($key, $before) ? $before[$key] : $this->tables[$table]['key_type'];
                $this->tables[$table]['key_type'] = $alteration['type'];
                $this->tables[$table]['source'] = $alteration['source'];
            }

            foreach ($this->morphKeys as $i => $morph) {
                if ($morph['table'] === $table && $morph['column'] === $column) {
                    $before[$key] = array_key_exists($key, $before) ? $before[$key] : $morph['type'];
                    $this->morphKeys[$i]['type'] = $alteration['type'];
                    $this->morphKeys[$i]['source'] = $alteration['source'];
                }
            }

            foreach ($this->foreignKeys as $i => $foreign) {
                if ($foreign['table'] === $table && $foreign['column'] === $column) {
                    $before[$key] = array_key_exists($key, $before) ? $before[$key] : $foreign['type'];
                    $this->foreignKeys[$i]['type'] = $alteration['type'];
                    $this->foreignKeys[$i]['source'] = $alteration['source'];
                }
            }
        }

        foreach ($before as $key => $original) {
            [$table, $column] = explode('.', $key, 2);

            $now = $column === 'id'
                ? ($this->tables[$table]['key_type'] ?? null)
                : ($this->typeOfTrackedColumn($table, $column));

            if ($original !== null && $now === null) {
                $this->unreadableAlterations++;
            }
        }
    }

    /** The current type of a tracked morph key or foreign key, whichever holds this column. */
    private function typeOfTrackedColumn(string $table, string $column): ?string
    {
        foreach ([...$this->morphKeys, ...$this->foreignKeys] as $tracked) {
            if ($tracked['table'] === $table && $tracked['column'] === $column) {
                return $tracked['type'];
            }
        }

        return null;
    }

    /**
     * Bind each model to its table — the declared `$table`, else Laravel's snake-case plural of the class
     * name, which is what Eloquent itself would do.
     */
    private function attachModels(): void
    {
        foreach ($this->models as $model) {
            $table = $model['table'] ?? $this->pluralize($this->snake($model['class']));

            if (! isset($this->tables[$table])) {
                continue;
            }

            $this->tables[$table]['models'][] = [
                'class' => $model['class'],
                'fqcn' => $model['fqcn'],
                'key_type' => $model['key_type'],
            ];
        }
    }

    private function snake(string $value): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    /** Deliberately naive — the estate's colliding tables (`users`, `sessions`, `teams`) are all regular. */
    private function pluralize(string $value): string
    {
        if (str_ends_with($value, 'y') && ! preg_match('/[aeiou]y$/', $value)) {
            return substr($value, 0, -1).'ies';
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $value)) {
            return $value.'es';
        }

        return str_ends_with($value, 's') ? $value : $value.'s';
    }

    /** @return \Generator<string> */
    private function walk(string $root): \Generator
    {
        if (! is_dir($root)) {
            return;
        }

        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
            static function (\SplFileInfo $entry) use ($root): bool {
                $rel = ltrim(substr($entry->getPathname(), strlen($root)), '/\\');

                return ! FacadeConformanceScope::isExcludedRelativePath($entry->isDir() ? $rel.'/' : $rel);
            },
        );

        foreach (new \RecursiveIteratorIterator($filter) as $entry) {
            /** @var \SplFileInfo $entry */
            $path = $entry->getPathname();

            if ($entry->isFile() && (str_ends_with($path, '.php') || str_ends_with($path, '.php.stub'))) {
                yield $path;
            }
        }
    }
}
