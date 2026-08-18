<?php

namespace Splicewire\Beam\Schema;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Fluent;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Support\Traits\Tappable;

/**
 * A CONVERGENT migration guard: "ensure this table has this shape", in place of "create this table".
 *
 * ## The failure it exists to end
 *
 * `spatie/laravel-package-tools` stamps a published migration with `now()`; vendor packages ship
 * fixed-date copies. Two migrations creating the SAME table therefore race on filename, and because
 * both sides guard with a bare `Schema::hasTable($t) → return`, **whichever sorts first wins and the
 * loser's guard reports success** — `migrate:fresh` exits green having produced a schema the app cannot
 * use. Sighted three times across the beam-facade effort: lunarphp's `activity_log` and the bigint-vs-uuid
 * `roles.id` break (ticket 13), two `create_media_table`s (16/17), and the submissions fork (20/23).
 *
 * Convergence removes the race rather than arbitrating it. Whoever runs first creates; whoever runs
 * second tops up what is missing; filename order stops mattering. See
 * `docs/agents/convergent-migration-guards.convention.md` for the rule and the estate's obligations
 * under it, and `docs/agents/migration-publish-ordering.convention.md` for the OTHER half of the
 * collision family (cross-package ALTER-vs-CREATE), which this does not address.
 *
 * ## The three tiers
 *
 * | state                                        | verdict                  |
 * | -------------------------------------------- | ------------------------ |
 * | table absent                                 | create it                |
 * | table present, declared columns absent       | **add them** — converge  |
 * | table present, column present, wrong type    | **throw** — cannot converge |
 *
 * Tier three is the load-bearing one. Convergence handles ABSENCE, never CONFLICT, and both sightings
 * with proven damage were type conflicts: Lunar's `activity_log` does not LACK the morph columns, it has
 * them as bigint `nullableMorphs` where beam needs strings, so `hasColumns()` returns true and a
 * top-up-what-is-missing guard would top up nothing. {@see SchemaConflict} carries the two further
 * shapes convergence cannot reach — a NOT NULL addition to a populated table, and NOT NULL residue the
 * declaration does not know about (beam-facade ticket 23).
 *
 * **No DDL is written when a conflict is found.** A conflicted run leaves the table exactly as it was,
 * so the two terminals differ only in how loud they are, never in what they did.
 *
 * ## Two terminals, both required
 *
 * `->assert()` throws {@see SchemaConvergenceConflict}; `->matches()` returns a bool and stays quiet.
 * One terminal would force every call site into the other's shape — a stale-fork collision wants the
 * throw, while the shared-test-DB harness and "Lunar wins harmlessly in a host with no central audit
 * trail" want the quiet return.
 *
 * ```php
 * ConvergentTable::named(Beam::table('ownership_edges'))
 *     ->define(function (Blueprint $t) {
 *         $t->uuid('id')->primary();
 *         $t->uuid('owner_id');
 *         $t->timestamps();
 *     })
 *     ->assert();
 * ```
 *
 * ## What it deliberately does not do
 *
 * **Per-column type conversion** (`->change()`). Ruled out at beam-facade ticket 22 with a trigger: build
 * it when a real host must upgrade THROUGH a type conflict. Every sighting so far needed detection plus
 * someone told, not conversion — and designing a conversion API against zero call sites is the shape this
 * estate has refused twice. The extension point is `Macroable` plus {@see ConvergenceReport}, which names
 * the conflicting column: a host that must convert can macro one on without touching this class.
 *
 * **Foreign keys and primary keys on an EXISTING table.** The create path applies whatever the definition
 * declares; the converge path adds columns and plain/unique/full-text indexes only. A primary key cannot
 * be added after the fact on sqlite, and a foreign key added to a populated table fails on the rows that
 * are already there — both are deliberate migrations, not convergence.
 *
 * Home is `laravel-beam/src/Schema/` even though nothing here is beam-specific, on the estate's
 * promote-only-once-proven rule. A second family package needing it is the promotion trigger.
 */
class ConvergentTable
{
    use Conditionable, Macroable, Tappable;

    protected ?Closure $definition = null;

    protected ?Closure $exists = null;

    protected ?string $connection = null;

    /** @var list<string> */
    protected array $ignored = [];

    public function __construct(public string $table) {}

    public static function named(string $table): static
    {
        return new static($table);
    }

    /** Run against a named connection instead of the default. */
    public function on(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * The shape this table must have — an ordinary Blueprint closure, identical to the one a
     * `Schema::create()` would take. It is run twice: once for real when the table is absent, and once
     * against a throwaway Blueprint (which emits no SQL) to read the declaration when it is present.
     */
    public function define(Closure $definition): static
    {
        $this->definition = $definition;

        return $this;
    }

    /**
     * Override how "does this table exist" is answered — the escape hatch for a substrate where
     * `Schema::hasTable()` is the wrong question.
     *
     * The case that earned it: under stancl schema-per-tenant on Postgres, the search_path is
     * `("tenant_x", public)`, so a plain `hasTable` INSIDE a tenant sees the central copy and skips
     * creating the tenant-local one. The predicate receives the resolved table name.
     */
    public function existsUsing(Closure $predicate): static
    {
        $this->exists = $predicate;

        return $this;
    }

    /**
     * Exempt existing columns from the NOT-NULL-residue check — for a host filling a required column of
     * its own deliberately. Nothing else about them is inspected either way.
     */
    public function ignoringColumns(string ...$columns): static
    {
        $this->ignored = array_values(array_unique([...$this->ignored, ...array_map('strtolower', $columns)]));

        return $this;
    }

    /**
     * Converge, and throw {@see SchemaConvergenceConflict} if the table cannot be converged.
     * The loud terminal — the default for anything whose absence would corrupt data.
     */
    public function assert(): ConvergenceReport
    {
        $report = $this->converge();

        if ($report->hasConflicts()) {
            throw SchemaConvergenceConflict::report($report);
        }

        return $report;
    }

    /**
     * Converge, and report whether the table now matches — never throwing. The quiet terminal, for the
     * call sites that genuinely do not care which shape won.
     */
    public function matches(): bool
    {
        return $this->converge()->converged();
    }

    protected function converge(): ConvergenceReport
    {
        if ($this->definition === null) {
            throw new BadMethodCallException(
                "ConvergentTable for `{$this->table}` has no definition — call `define()` before a terminal."
            );
        }

        $schema = Schema::connection($this->connection);

        if (! $this->tableExists($schema)) {
            $schema->create($this->table, $this->definition);

            return new ConvergenceReport($this->table, created: true);
        }

        $shape = new DeclaredShape($schema->getConnection(), $this->table, $this->definition);
        $driver = $schema->getConnection()->getDriverName();

        $existing = [];
        foreach ($schema->getColumns($this->table) as $column) {
            $existing[strtolower($column['name'])] = $column;
        }

        $missing = [];
        $conflicts = [];
        $unverified = [];
        $declared = [];

        foreach ($shape->getColumns() as $definition) {
            $name = strtolower($definition->name);
            $declared[] = $name;

            if (! isset($existing[$name])) {
                $missing[] = $definition;

                continue;
            }

            $verdict = ColumnTypeEquivalence::matches($driver, (string) $definition->type, (string) $existing[$name]['type_name']);

            if ($verdict === null) {
                $unverified[] = $definition->name;
            } elseif ($verdict === false) {
                $conflicts[] = SchemaConflict::type(
                    $this->table,
                    $definition->name,
                    (string) $definition->type,
                    (string) $existing[$name]['type_name'],
                );
            }
        }

        // Tier two has a limit convergence cannot reach: a NOT NULL column with no default cannot be
        // added to a table that already holds rows. Only worth asking once, and only if something is
        // actually missing.
        $populated = null;
        foreach ($missing as $definition) {
            if (! $this->isRequired($definition)) {
                continue;
            }

            $populated ??= $schema->getConnection()->table($this->table)->exists();

            if ($populated) {
                $conflicts[] = SchemaConflict::requiredAddition($this->table, $definition->name);
            }
        }

        // The other direction, which a top-up-only guard converges cleanly and then breaks on: a column
        // this declaration does not know about, NOT NULL with no default, so every insert we make fails.
        foreach ($existing as $name => $column) {
            if (in_array($name, $declared, true) || in_array($name, $this->ignored, true)) {
                continue;
            }

            if ($column['nullable'] || $column['default'] !== null || $column['auto_increment']) {
                continue;
            }

            $conflicts[] = SchemaConflict::requiredResidue($this->table, $column['name']);
        }

        if ($conflicts !== []) {
            return new ConvergenceReport($this->table, conflicts: $conflicts, unverified: $unverified);
        }

        if ($missing !== []) {
            $schema->table($this->table, function (Blueprint $table) use ($missing) {
                foreach ($missing as $definition) {
                    $attributes = $definition->getAttributes();
                    unset($attributes['type'], $attributes['name']);

                    $table->addColumn((string) $definition->type, (string) $definition->name, $attributes);
                }
            });
        }

        return new ConvergenceReport(
            $this->table,
            addedColumns: array_map(fn ($definition) => (string) $definition->name, $missing),
            // AFTER the column add, deliberately: `declaredIndexes()` consumes the fluent index
            // attributes, and a re-added column carries its own along to the ALTER.
            addedIndexes: $this->convergeIndexes($schema, $shape),
            unverified: $unverified,
        );
    }

    /**
     * Indexes the definition declares that the live table does not have — checked by NAME and by COLUMN
     * SET, because a competitor's copy of the table will have named its indexes its own way and creating
     * a duplicate unique index is a hard failure rather than a no-op.
     *
     * @return list<string>
     */
    protected function convergeIndexes(Builder $schema, DeclaredShape $shape): array
    {
        $added = [];

        foreach ($shape->declaredIndexes() as $command) {
            if (! in_array($command->name, ['index', 'unique', 'fullText'], true)) {
                continue;
            }

            $columns = (array) $command->columns;
            $index = (string) $command->index;

            if ($schema->hasIndex($this->table, $index) || $schema->hasIndex($this->table, $columns)) {
                continue;
            }

            $method = (string) $command->name;

            $schema->table($this->table, fn (Blueprint $table) => $table->{$method}($columns, $index));

            $added[] = $index;
        }

        return $added;
    }

    protected function tableExists(Builder $schema): bool
    {
        return $this->exists !== null
            ? (bool) ($this->exists)($this->table)
            : $schema->hasTable($this->table);
    }

    /** NOT NULL, no default, and not generated — the only shape that cannot be added to a populated table. */
    protected function isRequired(Fluent $definition): bool
    {
        return ! $definition->nullable
            && $definition->default === null
            && ! $definition->autoIncrement
            && $definition->virtualAs === null
            && $definition->storedAs === null;
    }
}
