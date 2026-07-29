<?php

declare(strict_types=1);

namespace Splicewire\Beam\Schema;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\SchemaFingerprint;
use Schemastud\DataSchemas\Lifecycle\SchemaRegistryConflict;

/**
 * DB-backed {@see SchemaRegistry} — the runtime sibling of the
 * {@see FilesystemSchemaRegistry}.
 *
 * Where the filesystem registry holds a host's COMMITTED code schemas, this
 * resolves schemas a downstream tenant registered at RUNTIME (no deploy). It is
 * backed by a `schema_registry` table (UUID PK, `schema_id` unique). A frozen
 * artifact is keyed by its absolute, versioned `$id`.
 *
 * The mechanism is host-agnostic: the `$table` and `$connection` are injected, so
 * a multi-tenant host binds it per-resolution against the active tenant connection
 * (pass `null` for the default connection) while a single-DB host binds it once.
 *
 * Immutability / write-once mirrors the filesystem store exactly:
 *  - registering an `$id` that already exists with the SAME structural
 *    {@see SchemaFingerprint} is an idempotent no-op (re-publish);
 *  - a DIFFERENT fingerprint throws {@see SchemaRegistryConflict} — a changed
 *    shape requires a new `$id`/version.
 */
class DatabaseSchemaRegistry implements EnumeratesVersions, SchemaRegistry
{
    public function __construct(
        protected string $table = 'schema_registry',
        protected ?string $connection = null,
    ) {}

    public function register(array $schema): void
    {
        $id = $schema['$id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('Cannot register a schema without an $id.');
        }

        $incoming = SchemaFingerprint::of($schema);

        $existing = $this->query()->where('schema_id', $id)->first();
        if ($existing !== null) {
            if ($existing->fingerprint === $incoming) {
                return; // Idempotent re-publish of the identical shape.
            }

            throw new SchemaRegistryConflict($id, $existing->fingerprint, $incoming);
        }

        $this->query()->insert([
            'id' => (string) Str::uuid(),
            'schema_id' => $id,
            'schema_name' => SchemaId::from($id)->stem(),
            'version' => SchemaId::from($id)->version(),
            'fingerprint' => $incoming,
            'artifact' => json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function get(string $id): ?array
    {
        $row = $this->query()->where('schema_id', $id)->first();
        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row->artifact, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function has(string $id): bool
    {
        return $this->query()->where('schema_id', $id)->exists();
    }

    public function ids(): array
    {
        return $this->query()
            ->orderBy('schema_id')
            ->pluck('schema_id')
            ->all();
    }

    /**
     * The version integers registered under `$stem`, ascending and de-duplicated.
     *
     * Answered by the INDEXED `schema_name`/`version` columns written on every
     * `register()` — the runtime tier's cheap version enumeration (no full scan,
     * no artifact decode). An unknown stem yields an empty array.
     *
     * @return array<int, int>
     */
    public function versionsFor(string $stem): array
    {
        return $this->query()
            ->where('schema_name', $stem)
            ->whereNotNull('version')
            ->orderBy('version')
            ->distinct()
            ->pluck('version')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** A fresh query builder bound to the configured connection + table. */
    protected function query(): Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }
}
