<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\Support\SchemaKeyIndex;

/**
 * **A table's primary key and the model that reads it must agree, and a foreign key must match the key
 * it points at.** The estate's silent-schema-defect check.
 *
 * ## Why "agree" and not "must be uuid"
 * The estate keys its own tables by uuid, and the temptation is to check for that. It would be wrong:
 * `create_activity_log_table` ships `$table->id()` because it is spatie's shape, and beam vendors it
 * verbatim. A check that mandates a type flags every legitimately-bigint vendor table on day one and is
 * switched off within the week. **Agreement is decidable without an opinion** — the migration and the
 * model are two authored statements about one fact, and when they disagree exactly one of them is
 * wrong regardless of which type the estate prefers.
 *
 * ## The failure it exists to catch, which is silent by construction
 * Found at `~/Herd/splicewire`: `users.id` was declared `uuid('id')->primary()` by the published
 * migration while `App\Models\User` declared no key type at all. Three things followed, none of which
 * raised an error:
 *
 *  1. Eloquent assumed an auto-incrementing integer key and generated no uuid on insert.
 *  2. `create_passkeys_table` uses `foreignIdFor(Passkeys::userModel(), 'user_id')`, which derives the
 *     column type **from the model** — so a `User` without `HasUuids` emitted a **BIGINT foreign key
 *     pointing at a uuid primary key**. SQLite stores that without complaint; MySQL would reject the
 *     constraint, so the defect ships in dev and detonates on the first real database.
 *  3. `migrate` reported success throughout.
 *
 * The root cause was upstream of all of it: `laravel-satellite-starter` shipped `$table->id()` while
 * `laravel-beam-starter` shipped `uuid('id')`, so **two starters disagreed about the identity of
 * `users`** and every site born from the wrong one inherited a key type nothing ever checked.
 *
 * ## Predicates
 *  - **`pk-model-disagreement`** — a `Schema::create` declares the table's primary key one way and the
 *    model bound to that table declares it another (`HasUuids`/`HasUlids`/`$keyType`, plus
 *    `$incrementing = false`, which is read as a **veto** on the integer default and not as a type of its
 *    own — a model that only denies auto-increment indexes as unknown, therefore skipped. See
 *    {@see SchemaKeyIndex}'s `indexModel()`, and note that this line described a check nothing ran until
 *    realm-and-floor-reconciliation.
 *  - **`fk-target-disagreement`** — a column declared `foreignId(...)` (integer by definition) points at
 *    a table whose primary key is a uuid/ulid/string, or the reverse.
 *  - **`third-party-key-binding`** — an estate-published migration keys a table by uuid while the model
 *    that reads it lives in `vendor/` and keys it by integer. See {@see DEFAULT_THIRD_PARTY_BINDINGS}.
 *  - **`morph-key-holder-disagreement`** — a polymorphic `<prefix>_id` is declared one type while the
 *    table whose key it holds is keyed another. The one join in the estate the schema never declares, so
 *    `fk-target-disagreement` cannot reach it by construction. See {@see DEFAULT_MORPH_KEY_HOLDERS}.
 *
 * `foreignIdFor(Model::class)` is deliberately **not** flagged directly: it derives its type from the
 * model, so it is correct exactly when the model is, and the model is what the first predicate already
 * governs. Flagging it too would report one defect twice and point the reader at the wrong file.
 *
 * ## Honesty about reach
 * Static, over source — no database connection, so it runs in any host at any time, including before a
 * single migration has been applied. The cost is that a table name it cannot resolve statically is a
 * table it cannot check: {@see SchemaKeyIndex} resolves `protected $table = '<literal>'` and Laravel's
 * snake-case plural convention, and skips a model whose name is computed at runtime. Those skips are
 * **counted in the Pass line** rather than hidden, because "no findings" and "could not look" are
 * different facts.
 *
 * The index also excludes `vendor/`, which is right — no fix belongs in a third-party file — but leaves
 * it blind to a table the estate publishes and a vendor model reads. {@see DEFAULT_THIRD_PARTY_BINDINGS}
 * closes that by naming those bindings explicitly and reading the bound class by reflection rather than
 * by scanning `vendor/`, so the check stays static, connection-free, and reportable at the first-party
 * file where the fix belongs. A binding it cannot load is counted in the Pass line too.
 *
 * A column's type is the **last** thing a migration declares about it, not the first
 * ({@see SchemaKeyIndex}'s second pass, ticket 144). A host that got its create wrong and repaired it
 * with a follow-on `Schema::table(...)` used to read as broken forever — measured at `~/Herd/fable-legacy`,
 * whose live database agreed with the repair and not with the create this audit was quoting back at it.
 * The reader now follows those repairs where it can read them and **un-knows the column where it cannot**
 * (raw DDL that renames a column into another's place, a SQL type it has no mapping for): unknown is a
 * skip in every predicate here, so the reader never reports a type a later statement contradicted. Those
 * skips are counted in the Pass line, which is the only place the difference between "agrees" and "could
 * not read the repair" is visible.
 *
 * Advisory, like every other conformance check beam registers — but note this one reports a defect that
 * is already in a schema rather than a style drift, so a finding here is worth acting on immediately.
 */
class KeyTypeConformanceAudit implements DoctorAudit
{
    public const CHECK = 'beam.schema.key-type-conformance';

    /**
     * Tables the estate keys by uuid as a matter of standing convention, checked **even when the site is
     * internally consistent**.
     *
     * This predicate exists because agreement alone is not enough, and the estate proved it: `entreport`,
     * `fable`, `numero`, `schemastud`, `standwell`, `stephenrushing` and `beam-pilot` all declare a bigint
     * `users` column AND a model that agrees with it. Nothing disagrees, so the agreement predicate passes
     * every one of them — while the whole estate is wrong in the same direction, which is precisely how it
     * got that way: `laravel-satellite-starter` shipped `$table->id()`, every site born from it inherited a
     * consistent mistake, and no check ever had an opinion.
     *
     * Kept to a **named list rather than "everything must be uuid"** for the reason the class docblock
     * gives: `activity_log` is spatie's shape vendored verbatim and is legitimately an auto-incrementing
     * integer. `users` is on the list because identity is the one key the estate federates on. Extend it
     * per host via `beam.core.schema.uuid_tables`; a host that genuinely retrofits onto a foreign bigint
     * `users` sets it to `[]` and says so in config, which is a visible decision rather than a silent drift.
     *
     * @var list<string>
     */
    public const DEFAULT_UUID_TABLES = ['users'];

    /**
     * Tables the estate **publishes a migration for** but whose reading model ships in `vendor/` —
     * `table => [class, config key that may override it]`.
     *
     * ## The blind spot this closes
     * {@see SchemaKeyIndex} excludes `vendor/` — correctly, because a third-party file is not somewhere a
     * fix belongs — and the consequence is that it sees every first-party model and no other. That makes
     * it blind to the estate's single most damaging shape: **an estate-published migration whose binding
     * model is third-party.** `splicewire/laravel-beam-accounts` publishes `create_permission_tables`
     * with `$table->uuid('id')->primary()`; a host that does not also override
     * `config('permission.models.role')` gets `Spatie\Permission\Models\Role`, which is a plain Eloquent
     * model with an auto-incrementing key. It generates no identifier on insert, and the insert fails
     * with `null value in column "id" of relation "roles"` — which is exactly what
     * `splicewire/tower`'s `tests/Feature/Chat` does today, while the audit reported nothing.
     * Both halves of that defect are first-party decisions; only the model is in a directory nobody scans.
     *
     * ## Why a named registry and not "every table with no first-party model"
     * The obvious generalisation — *a uuid table that no first-party model binds must be bound by a
     * third-party one, by elimination* — was measured against the estate and rejected. Elimination is
     * false here: {@see SchemaKeyIndex} resolves a model's table from `protected $table` or Laravel's
     * snake-case plural and nothing else, so `ComplianceEvidence` → `compliance_evidences` fails to bind
     * a perfectly ordinary first-party `compliance_evidence` table. Across the estate the index leaves
     * 70–160 models unbound per host; every uuid table one of them owns would be a fresh false positive,
     * and the check would be off within the week for the same reason a blanket uuid mandate would be.
     *
     * ## Why this is not "keying on a class name"
     * The census ruling (10 §7) is that a class *name* is not evidence — a naive name-keyed check ran at
     * 100% false positives on constructor-injected classes. Nothing here is decided by a name. The
     * predicate needs three independent facts to agree: an estate migration must actually declare the
     * table non-integer, no first-party model may bind it (if one does, `pk-model-disagreement` already
     * owns the case and reporting it twice would point the reader at the wrong file), and the class must
     * be **loadable and read by reflection** to say for itself what key type it uses
     * ({@see SchemaKeyIndex::keyTypeOfClass()}). A registry entry alone reports nothing. If the package
     * is absent, or has since adopted `HasUuids`, or the host swapped in its own model, there is no
     * finding — and an entry that could not be resolved is **counted in the Pass line**, like every other
     * skip this audit makes.
     *
     * Extend per host via `beam.core.schema.third_party_bindings`.
     *
     * @var array<string, array{class: string, config: string}>
     */
    public const DEFAULT_THIRD_PARTY_BINDINGS = [
        'roles' => ['class' => 'Spatie\Permission\Models\Role', 'config' => 'permission.models.role'],
        'permissions' => ['class' => 'Spatie\Permission\Models\Permission', 'config' => 'permission.models.permission'],
    ];

    /**
     * Morph pivots whose **holder** — the table whose primary key `<prefix>_id` stores — is fixed by
     * convention: `pivot table => holder table`.
     *
     * ## Why a morph key needs its own predicate
     * `fk-target-disagreement` walks declared foreign keys, and a morph key declares none: it points at
     * nothing, by construction, because the thing it points at is named in a *sibling column at runtime*.
     * So the one join in the estate that no schema states is the one no predicate could see, and it went
     * unseen — measured 2026-08-26 at `~/Herd/fable` and `~/Herd/numero`, where
     * `model_has_roles.model_id` is `unsignedBigInteger` while `users.id` is `uuid('id')->primary()`.
     * `HasRoles::roles()` is a `morphToMany`, so `role()`, `permission()` and any `whereHas('roles')`
     * emit a correlated subquery comparing the two **columns** directly:
     *
     *     select * from "users" where exists (
     *         select * from "roles"
     *         inner join "model_has_roles" on "roles"."id" = "model_has_roles"."role_id"
     *         where "users"."id" = "model_has_roles"."model_id" ...)
     *
     * Postgres has no implicit `uuid ↔ bigint` cast, so every one of those dies with `operator does not
     * exist: uuid = bigint`. Both hosts run SQLite locally, which stores the mismatch without complaint —
     * the same "ships in dev, dies on the first real database" shape this whole audit exists for.
     *
     * ## Why a named registry, and not "every morph key must match `users`"
     * A morph key is polymorphic *on purpose*: `activity_log.subject_id` legitimately holds the key of
     * any model in the estate, and there is no single holder to compare it against. Generalising would
     * flag every such column on day one and the check would be off within the week — the same failure the
     * class docblock rejects a blanket uuid mandate for. These two entries are different because the
     * holder is not open: a permission pivot holds the key of whatever model uses `HasRoles`, and the
     * estate federates identity on `users` (the same premise {@see DEFAULT_UUID_TABLES} rests on). A host
     * that genuinely lets a differently-keyed model hold roles sets `beam.core.schema.morph_key_holders`
     * and makes that a visible decision.
     *
     * Reported only when **both** types are known and the holder's key type is actually indexed, so a
     * host whose `users` no migration in scope creates gets no finding rather than a guess.
     *
     * @var array<string, string>
     */
    public const DEFAULT_MORPH_KEY_HOLDERS = [
        'model_has_roles' => 'users',
        'model_has_permissions' => 'users',
    ];

    /** Registry entries that named a class this host cannot load — skipped, and reported as skipped. */
    protected int $unresolvedBindings = 0;

    /**
     * @param  list<string>|null  $uuidTables
     * @param  array<string, array{class: string, config?: string}>|null  $thirdPartyBindings
     * @param  array<string, string>|null  $morphKeyHolders
     */
    public function __construct(
        protected SchemaKeyIndex $index,
        protected ?array $uuidTables = null,
        protected ?array $thirdPartyBindings = null,
        protected ?array $morphKeyHolders = null,
    ) {}

    public static function forApp(?SchemaKeyIndex $index = null): self
    {
        return new self(
            $index ?? SchemaKeyIndex::forApp(),
            (array) config('beam.core.schema.uuid_tables', self::DEFAULT_UUID_TABLES),
            (array) config('beam.core.schema.third_party_bindings', self::DEFAULT_THIRD_PARTY_BINDINGS),
            (array) config('beam.core.schema.morph_key_holders', self::DEFAULT_MORPH_KEY_HOLDERS),
        );
    }

    /** @return list<string> */
    protected function uuidTables(): array
    {
        return array_values($this->uuidTables ?? self::DEFAULT_UUID_TABLES);
    }

    /** @return array<string, array{class: string, config?: string}> */
    protected function thirdPartyBindings(): array
    {
        return $this->thirdPartyBindings ?? self::DEFAULT_THIRD_PARTY_BINDINGS;
    }

    /** @return array<string, string> */
    protected function morphKeyHolders(): array
    {
        return $this->morphKeyHolders ?? self::DEFAULT_MORPH_KEY_HOLDERS;
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = $this->disagreements();

        $findings = $rows === []
            ? [Finding::pass(self::CHECK, sprintf(
                'Primary keys, models and foreign keys agree (%d table(s) and %d model(s) checked; '.
                '%d model(s) skipped — table name not statically resolvable; '.
                '%d third-party binding(s) skipped — class not loadable here; '.
                '%d column(s) skipped — altered after their create by DDL this reader cannot classify; '.
                '%d create(s) skipped — table name not statically resolvable; '.
                '%d foreignId-family column(s) skipped — the declaration states no target table).',
                $this->index->tableCount(),
                $this->index->modelCount(),
                $this->index->unresolvedModelCount(),
                $this->unresolvedBindings,
                $this->index->unreadableAlterationCount(),
                $this->index->unparsedCreateCount(),
                $this->index->unreferencedForeignKeyColumnCount(),
            ))]
            : array_map(fn (array $row): Finding => Finding::warn(self::CHECK, $row['detail']), $rows);

        if ($this->index->unparsedCreateCount() > 0) {
            $findings[] = $this->unparsedCreateFinding();
        }

        return $findings;
    }

    /**
     * The tables this audit **did not look at**, said out loud — beam-facade 191.
     *
     * The Pass line counted three kinds of skip and not this one, so a create whose name is a method call
     * or a concatenation left no trace anywhere: not in `tables()`, not in a finding, not in the skip
     * tally. The reader indexed 28 tables of 164 at the flagship and 5 of 29 at `~/Herd/tower` and
     * reported *"primary keys, models and foreign keys agree"* over both. **An instrument that enumerates
     * its known blind spots and not its unknown ones reads as thorough precisely where it is weakest.**
     *
     * Emitted alongside the Pass rather than instead of it: "everything I could read agrees" and "here is
     * what I could not read" are two true statements and the report needs both. Warn, never Fail — this
     * is a fact about the reader's reach, and nothing a host authored is wrong because of it.
     */
    protected function unparsedCreateFinding(): Finding
    {
        $files = $this->index->unparsedCreateFiles();
        arsort($files);
        $named = array_slice(array_keys($files), 0, 6);

        return Finding::warn(self::CHECK, sprintf(
            '%d create(s) across %d file(s) name a table this reader cannot resolve statically — a '
            .'`Schema::create(Beam::table(...))` or a `ConvergentTable::named($this->target())`, whose '
            .'result is a concatenation or a method call rather than a spelling. Those tables are in '
            .'none of the counts above: they were not checked, and until now nothing said so. %s%s '
            .'Nothing is necessarily wrong in them; this is the reach of the check, reported rather '
            .'than absorbed, because "agrees" and "was never looked at" are different facts and only '
            .'one of them was previously visible.',
            $this->index->unparsedCreateCount(),
            count($files),
            implode(', ', $named),
            count($files) > 6 ? sprintf(' (+%d more).', count($files) - 6) : '.',
        ));
    }

    /**
     * Every key-type disagreement, as sorted rows — the work-list.
     *
     * @return list<array{kind: string, table: string, detail: string}>
     */
    public function disagreements(): array
    {
        $rows = [];
        $this->unresolvedBindings = 0;

        foreach ($this->index->tables() as $table => $meta) {
            $declared = $meta['key_type'];

            foreach ($meta['models'] as $model) {
                $modelType = $model['key_type'];

                if ($this->modelAgrees($declared, $modelType)) {
                    continue;
                }

                $rows[] = [
                    'kind' => 'pk-model-disagreement',
                    'table' => $table,
                    'detail' => sprintf(
                        '`%s` declares a %s primary key (%s) but %s declares %s. Eloquent will key this '
                        .'model wrongly — it generates no identifier on insert when it believes the key '
                        .'auto-increments, and any `foreignIdFor()` elsewhere derives its column type from '
                        .'the model, so the mismatch spreads into foreign keys that a real database will reject.',
                        $table,
                        $declared,
                        $meta['source'],
                        $model['class'],
                        $modelType === 'int' ? 'an auto-incrementing integer key (no HasUuids/$keyType)' : "a {$modelType} key",
                    ),
                ];
            }
        }

        foreach ($this->uuidTables() as $table) {
            $declared = $this->index->keyTypeOf($table);

            if ($declared === null || $declared === 'uuid') {
                continue;
            }

            $rows[] = [
                'kind' => 'identity-key-convention',
                'table' => $table,
                'detail' => sprintf(
                    '`%s` is keyed %s (%s), but the estate keys it by uuid. This is reported even though '
                    .'nothing here disagrees with itself — a site can be perfectly consistent and still be '
                    .'consistently wrong, which is exactly how this spread: a starter shipped `$table->id()` '
                    .'and every site born from it inherited the same bigint identity. Fix the column, any '
                    ."foreign key that points at it, and the model's `HasUuids`. If this host genuinely "
                    .'retrofits onto a foreign bigint table, set `beam.core.schema.uuid_tables` and make the '
                    .'exception visible.',
                    $table,
                    $declared,
                    $this->index->tables()[$table]['source'] ?? 'unknown',
                ),
            ];
        }

        foreach ($this->thirdPartyBindings() as $table => $entry) {
            $declared = $this->index->keyTypeOf($table);

            // No estate migration creates it, or it creates it as the integer key the vendor package
            // ships by default — either way this is not the estate publishing a shape a vendor cannot read.
            if ($declared === null || $declared === 'int') {
                continue;
            }

            $class = $this->boundClass($entry);

            // `pk-model-disagreement` already governs the exact class this table is bound to, so reporting
            // it here as well would say one thing twice. Matched on the **fully-qualified** name: tower has
            // a first-party `Splicewire\Tower\Models\Role` AND is bound to `Spatie\Permission\Models\Role`,
            // and a short-name match would have silently swallowed the very finding this predicate exists
            // for — measured, not theorised.
            if (in_array($class, array_column($this->index->modelsFor($table), 'fqcn'), true)) {
                continue;
            }

            $modelType = SchemaKeyIndex::keyTypeOfClass($class);

            if ($modelType === null) {
                $this->unresolvedBindings++;

                continue;
            }

            if ($this->modelAgrees($declared, $modelType)) {
                continue;
            }

            $candidates = array_values(array_filter(
                $this->index->modelsFor($table),
                static fn (array $model): bool => $model['key_type'] === $declared,
            ));

            $rows[] = [
                'kind' => 'third-party-key-binding',
                'table' => $table,
                'detail' => sprintf(
                    '`%s` is published with a %s primary key (%s), but the model bound to it is `%s`, which '
                    .'ships in `vendor/` and declares %s. Nothing first-party corrects it, so Eloquent '
                    .'generates no identifier on insert and the write fails with '
                    .'`null value in column "id" of relation "%s"` on the first real database. %sOr '
                    .'publish this table with `$table->id()` to match the package. Set '
                    .'`beam.core.schema.third_party_bindings` if this host binds a different class.',
                    $table,
                    $declared,
                    $this->index->tables()[$table]['source'] ?? 'unknown',
                    $class,
                    $modelType === 'int' ? 'an auto-incrementing integer key (no HasUuids/$keyType)' : "a {$modelType} key",
                    $table,
                    $candidates === []
                        ? sprintf('Point `%s` at a first-party model that uses `HasUuids`. ', $entry['config'] ?? 'the package config')
                        : sprintf(
                            'A first-party `%s` already exists and keys by %s — it is simply not wired: set `%s` to it. ',
                            $candidates[0]['fqcn'],
                            $declared,
                            $entry['config'] ?? 'the package config',
                        ),
                ),
            ];
        }

        foreach ($this->index->foreignKeys() as $fk) {
            $target = $this->index->keyTypeOf($fk['references']);

            if ($target === null || $fk['type'] === null || $target === $fk['type']) {
                continue;
            }

            $rows[] = [
                'kind' => 'fk-target-disagreement',
                'table' => $fk['table'],
                'detail' => sprintf(
                    '`%s.%s` is declared %s (%s) but points at `%s`, whose primary key is %s. SQLite stores '
                    .'this without complaint; MySQL rejects the constraint, so it ships in dev and fails on '
                    .'the first real database.',
                    $fk['table'],
                    $fk['column'],
                    $fk['type'],
                    $fk['source'],
                    $fk['references'],
                    $target,
                ),
            ];
        }

        $holders = $this->morphKeyHolders();

        foreach ($this->index->morphKeys() as $morph) {
            $holder = $holders[$morph['table']] ?? null;

            if ($holder === null || $morph['type'] === null) {
                continue;
            }

            $target = $this->index->keyTypeOf($holder);

            if ($target === null || $target === $morph['type']) {
                continue;
            }

            $rows[] = [
                'kind' => 'morph-key-holder-disagreement',
                'table' => $morph['table'],
                'detail' => sprintf(
                    '`%s.%s` is declared %s (%s) but holds the primary key of `%s`, which is %s. A morph '
                    .'key declares no foreign key, so nothing in the schema states this join and no '
                    .'foreign-key check can reach it — but Eloquent joins on it anyway: `morphToMany` '
                    .'scopes emit `where "%s"."id" = "%s"."%s"` comparing the two columns directly, and '
                    .'Postgres has no implicit %s cast, so every such query dies with `operator does not '
                    .'exist`. SQLite stores it without complaint, which is why this ships in dev. Match '
                    .'the column to the holder — widening it to `string` does not help, because a column '
                    .'Eloquent joins on has none of the coercion a bound `where ... = ?` gets.',
                    $morph['table'],
                    $morph['column'],
                    $morph['type'],
                    $morph['source'],
                    $holder,
                    $target,
                    $holder,
                    $morph['table'],
                    $morph['column'],
                    $target.' ↔ '.$morph['type'],
                ),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['table'], $a['kind']] <=> [$b['table'], $b['kind']]);

        return $rows;
    }

    /**
     * Whether a model's declared key type agrees with the column's — compared on the **integer / not
     * integer** axis, because that is the only distinction a model is able to state.
     *
     * Eloquent gives a model exactly one lever, `$keyType` plus `$incrementing`, and every non-integer
     * spelling of it collapses to the same thing: {@see SchemaKeyIndex::keyTypeOfClass()} and
     * `indexModel()` both read `$keyType = 'string'` as `uuid` because there is nothing finer to read.
     * A column, meanwhile, can be `uuid`, `ulid`, `char` or `varchar`. Comparing those two vocabularies
     * for strict equality manufactures a finding out of the reader's own lossiness — measured at
     * `~/Herd/prahsys-gateway`, whose `users.id` is `string('id', 255)->primary()` and whose `User`
     * declares `$keyType = 'string'`: the same fact, twice, spelled the only two ways each side can
     * spell it.
     *
     * What it still catches, undiminished, is the defect the audit exists for: a non-integer column
     * against a model that says nothing and therefore auto-increments.
     */
    protected function modelAgrees(?string $declared, ?string $modelType): bool
    {
        if ($declared === null || $modelType === null || $declared === $modelType) {
            return true;
        }

        return $declared !== 'int' && $modelType !== 'int';
    }

    /**
     * The class actually bound to a registry entry's table: the host's config override where there is one,
     * else the package's own default.
     *
     * The override matters more than it looks. `~/Herd/splicewire` points `permission.models.role` at
     * `App\Models\Role`, which is first-party, in scope, and already governed — reading config is what
     * keeps this predicate from reporting a vendor class that host never instantiates.
     *
     * @param  array{class: string, config?: string}  $entry
     */
    protected function boundClass(array $entry): string
    {
        // Guarded, not assumed: the pure core of this audit is unit-driven with no application bound,
        // and `config()` throws rather than returning null when the container is absent.
        try {
            $configured = isset($entry['config']) && function_exists('config') ? config($entry['config']) : null;
        } catch (\Throwable) {
            $configured = null;
        }

        return is_string($configured) && $configured !== '' ? $configured : $entry['class'];
    }
}
