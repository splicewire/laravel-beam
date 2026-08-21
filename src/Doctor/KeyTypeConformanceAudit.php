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
 *    model bound to that table declares it another (`HasUuids`/`HasUlids`/`$keyType`/`$incrementing`).
 *  - **`fk-target-disagreement`** — a column declared `foreignId(...)` (integer by definition) points at
 *    a table whose primary key is a uuid/ulid/string, or the reverse.
 *  - **`third-party-key-binding`** — an estate-published migration keys a table by uuid while the model
 *    that reads it lives in `vendor/` and keys it by integer. See {@see DEFAULT_THIRD_PARTY_BINDINGS}.
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

    /** Registry entries that named a class this host cannot load — skipped, and reported as skipped. */
    protected int $unresolvedBindings = 0;

    /**
     * @param  list<string>|null  $uuidTables
     * @param  array<string, array{class: string, config?: string}>|null  $thirdPartyBindings
     */
    public function __construct(
        protected SchemaKeyIndex $index,
        protected ?array $uuidTables = null,
        protected ?array $thirdPartyBindings = null,
    ) {}

    public static function forApp(?SchemaKeyIndex $index = null): self
    {
        return new self(
            $index ?? SchemaKeyIndex::forApp(),
            (array) config('beam.core.schema.uuid_tables', self::DEFAULT_UUID_TABLES),
            (array) config('beam.core.schema.third_party_bindings', self::DEFAULT_THIRD_PARTY_BINDINGS),
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

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = $this->disagreements();

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'Primary keys, models and foreign keys agree (%d table(s) and %d model(s) checked; '.
                '%d model(s) skipped — table name not statically resolvable; '.
                '%d third-party binding(s) skipped — class not loadable here).',
                $this->index->tableCount(),
                $this->index->modelCount(),
                $this->index->unresolvedModelCount(),
                $this->unresolvedBindings,
            ))];
        }

        return array_map(fn (array $row): Finding => Finding::warn(self::CHECK, $row['detail']), $rows);
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

                if ($declared === null || $modelType === null || $declared === $modelType) {
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

            if ($modelType === $declared) {
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

        usort($rows, fn (array $a, array $b): int => [$a['table'], $a['kind']] <=> [$b['table'], $b['kind']]);

        return $rows;
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
