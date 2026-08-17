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
 */
class SchemaKeyIndex
{
    /** Column-declaration forms that mean "integer primary key". */
    public const INT_PK_FORMS = ['id', 'bigIncrements', 'increments', 'mediumIncrements', 'smallIncrements', 'tinyIncrements'];

    /** @var array<string, array{key_type: string|null, source: string, models: list<array{class: string, key_type: string|null}>}> */
    private array $tables = [];

    /** @var list<array{table: string, column: string, type: string|null, source: string, references: string}> */
    private array $foreignKeys = [];

    /** @var list<array{class: string, key_type: string|null, table: string|null}> */
    private array $models = [];

    private int $unresolvedModels = 0;

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

    /** @return array<string, array{key_type: string|null, source: string, models: list<array{class: string, key_type: string|null}>}> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return list<array{table: string, column: string, type: string|null, source: string, references: string}> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function keyTypeOf(string $table): ?string
    {
        return $this->tables[$table]['key_type'] ?? null;
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
        }

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

        $this->models[] = ['class' => $class[1], 'key_type' => $keyType, 'table' => $table];

        if ($table === null) {
            $this->unresolvedModels++;
        }
    }

    /**
     * Every `Schema::create('<table>', ...)` block's primary-key form and foreign-key columns.
     *
     * Only literal table names: a create whose name comes through `Beam::table(...)` resolves at runtime
     * against a config value this index cannot read, and guessing the prefix would invent findings. Those
     * creates are simply not indexed — beam's own tables are uuid by convention and consistent, and the
     * defect class this audit exists for lives in the framework-default tables (`users`, `sessions`,
     * `passkeys`) that are always named literally.
     */
    private function indexMigration(string $source, string $file): void
    {
        if (! preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]\s*,.*?\n(\s*)\}\)/s', $source, $creates, PREG_SET_ORDER)) {
            return;
        }

        foreach ($creates as $create) {
            [$block, $table] = [$create[0], $create[1]];

            $keyType = null;

            if (preg_match('/->(?:uuid|foreignUuid)\(\s*[\'"]id[\'"]\s*\)\s*->primary\(\)/', $block)) {
                $keyType = 'uuid';
            } elseif (preg_match('/->ulid\(\s*[\'"]id[\'"]\s*\)\s*->primary\(\)/', $block)) {
                $keyType = 'ulid';
            } elseif (preg_match('/->('.implode('|', self::INT_PK_FORMS).')\(/', $block)) {
                $keyType = 'int';
            }

            if ($keyType !== null && ! isset($this->tables[$table])) {
                $this->tables[$table] = [
                    'key_type' => $keyType,
                    'source' => basename($file),
                    'models' => [],
                ];
            }

            // Foreign keys declared with an explicit integer form. `foreignUuid` is the uuid counterpart;
            // `foreignIdFor(Model::class)` is omitted on purpose — it derives from the model, so it is
            // right exactly when the model is, which the primary-key predicate already governs.
            if (preg_match_all('/->foreignId\(\s*[\'"](\w+)_id[\'"]\s*\)/', $block, $fks, PREG_SET_ORDER)) {
                foreach ($fks as $fk) {
                    $this->foreignKeys[] = [
                        'table' => $table,
                        'column' => $fk[1].'_id',
                        'type' => 'int',
                        'source' => basename($file),
                        'references' => $this->pluralize($fk[1]),
                    ];
                }
            }
        }
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

            $this->tables[$table]['models'][] = ['class' => $model['class'], 'key_type' => $model['key_type']];
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
