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

    /** @var list<array{class: string, fqcn: string, key_type: string|null, table: string|null}> */
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
     * Creates whose name is a method call or a concatenation are still simply not indexed.
     */
    private function indexMigration(string $source, string $file): void
    {
        $creates = [];

        preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]\s*,.*?\n(\s*)\}\)/s', $source, $literal, PREG_SET_ORDER);

        foreach ($literal as $create) {
            $creates[] = [$create[0], $create[1], false];
        }

        preg_match_all('/Schema::create\(\s*\$\w+\[\s*[\'"](\w+)[\'"]\s*\]\s*,.*?\n(\s*)\}\)/s', $source, $configured, PREG_SET_ORDER);

        foreach ($configured as $create) {
            $creates[] = [$create[0], $create[1], true];
        }

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
                $this->tables[$table] = [
                    'key_type' => $keyType,
                    'source' => basename($file),
                    'inferred_name' => $inferredName,
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

            $this->indexMorphKeys($block, $table, $file);
        }
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
