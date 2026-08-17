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

    /** @param  list<string>|null  $uuidTables */
    public function __construct(protected SchemaKeyIndex $index, protected ?array $uuidTables = null) {}

    public static function forApp(?SchemaKeyIndex $index = null): self
    {
        return new self(
            $index ?? SchemaKeyIndex::forApp(),
            (array) config('beam.core.schema.uuid_tables', self::DEFAULT_UUID_TABLES),
        );
    }

    /** @return list<string> */
    protected function uuidTables(): array
    {
        return array_values($this->uuidTables ?? self::DEFAULT_UUID_TABLES);
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
                '%d model(s) skipped — table name not statically resolvable).',
                $this->index->tableCount(),
                $this->index->modelCount(),
                $this->index->unresolvedModelCount(),
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
                        "`%s` declares a %s primary key (%s) but %s declares %s. Eloquent will key this "
                        ."model wrongly — it generates no identifier on insert when it believes the key "
                        ."auto-increments, and any `foreignIdFor()` elsewhere derives its column type from "
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
                    "`%s` is keyed %s (%s), but the estate keys it by uuid. This is reported even though "
                    ."nothing here disagrees with itself — a site can be perfectly consistent and still be "
                    ."consistently wrong, which is exactly how this spread: a starter shipped `\$table->id()` "
                    ."and every site born from it inherited the same bigint identity. Fix the column, any "
                    ."foreign key that points at it, and the model's `HasUuids`. If this host genuinely "
                    .'retrofits onto a foreign bigint table, set `beam.core.schema.uuid_tables` and make the '
                    .'exception visible.',
                    $table,
                    $declared,
                    $this->index->tables()[$table]['source'] ?? 'unknown',
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
}
