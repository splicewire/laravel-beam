<?php

namespace Splicewire\Beam\Schema;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;

/**
 * The ONE config-driven schema registry (beam-particle-rename ticket 02).
 *
 * Collapses the former Filesystem/Database/Chained trio onto a single class driven by an ordered
 * SOURCES list (e.g. `['db','file']`, `['file']`). It does not reimplement any tier — it SELECTS
 * and ORDERS the existing per-tier {@see SchemaRegistry}s (the DB tier over `Beam::table('schemas')`,
 * the committed filesystem tier) from that list. The collapse is purely the ordering/selection seam
 * the host used to hand-wire as `new ChainedSchemaRegistry(new DatabaseSchemaRegistry, new Filesystem…)`.
 *
 * READ (`get`/`has`) walks the sources IN ORDER — the FIRST source is authoritative, so a `['db','file']`
 * host has the DB tier SHADOW the filesystem tier (a tenant's runtime-registered schema wins, falling
 * back to committed code artifacts), exactly as the former ChainedSchemaRegistry did (that BC shim is
 * now deleted — beam-particle-rename ticket 02 deferred its removal to the contract phase; done here).
 *
 * WRITE (`register`) lands in the FIRST WRITABLE source — for `['db','file']` that is the DB tier
 * (runtime registrations are tenant-owned, write-once, and must never mutate the committed code
 * artifacts on disk). A `['file']`-only host writes to the filesystem tier.
 *
 * VERSION ENUMERATION merges + de-duplicates across every source that can enumerate, so a stem frozen
 * partly in the DB tier and partly on disk reports a complete history.
 *
 * The tiers are LAZY closures: a `['file']` host never constructs the DB tier, so it boots + resolves
 * with NO `beam_schemas` table required. The {@see SchemaRegistry} contract is UNCHANGED — every
 * consumer ({@see RegistrySchemaTargetResolver}, the host's version-resolution authority
 * (`Splicewire\Tower\Schema\SchemaVersionResolver` — a tower class, not beam's), and
 * {@see SchemaLadderMigrator}) is untouched.
 */
class BeamSchemaRegistry implements EnumeratesVersions, SchemaRegistry
{
    /**
     * @param  array<int, string>  $sources  the ordered source keys, e.g. `['db','file']`.
     * @param  array<string, Closure(): SchemaRegistry>  $factories  a lazy tier factory per source key.
     */
    public function __construct(
        protected array $sources,
        protected array $factories,
    ) {
        if ($this->sources === []) {
            throw new InvalidArgumentException('BeamSchemaRegistry needs at least one source.');
        }

        foreach ($this->sources as $source) {
            if (! isset($this->factories[$source])) {
                throw new InvalidArgumentException("BeamSchemaRegistry has no tier factory for source '{$source}'.");
            }
        }
    }

    /**
     * Register a schema in the FIRST source (every configured tier is writable — a
     * {@see SchemaRegistry} always exposes `register()`). For `['db','file']` that is the DB tier.
     */
    public function register(array $schema): void
    {
        $id = $schema['$id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('Cannot register a schema without an $id.');
        }

        $this->tierFor($this->sources[0])->register($schema);
    }

    /**
     * Resolve by `$id`, walking the sources IN ORDER — the first tier that has it wins (a later tier
     * shadowed by an earlier one), so `['db','file']` is DB-first with filesystem fallback.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        foreach ($this->sources as $source) {
            $found = $this->tierFor($source)->get($id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function has(string $id): bool
    {
        foreach ($this->sources as $source) {
            if ($this->tierFor($source)->has($id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every `$id` across every source, de-duplicated and sorted.
     *
     * @return array<int, string>
     */
    public function ids(): array
    {
        $ids = [];
        foreach ($this->sources as $source) {
            $ids = array_merge($ids, $this->tierFor($source)->ids());
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * The version integers for `$stem` MERGED + de-duplicated across every source that can enumerate,
     * ascending — a tier that is not an {@see EnumeratesVersions} contributes nothing (additive delta).
     *
     * @return array<int, int>
     */
    public function versionsFor(string $stem): array
    {
        $versions = [];
        foreach ($this->sources as $source) {
            $tier = $this->tierFor($source);
            if ($tier instanceof EnumeratesVersions) {
                $versions = array_merge($versions, $tier->versionsFor($stem));
            }
        }

        $versions = array_values(array_unique($versions));
        sort($versions);

        return $versions;
    }

    /**
     * The tier for a source key, constructed lazily via its factory — so an unused source (e.g. the DB
     * tier under a `['file']` host) is NEVER built and needs no `beam_schemas` table. The factory is the
     * per-resolution construction seam: a multi-tenant host's DB-tier factory rebuilds against the active
     * tenant connection each call, matching the former per-resolution `new DatabaseSchemaRegistry` wiring.
     */
    protected function tierFor(string $source): SchemaRegistry
    {
        $tier = ($this->factories[$source])();

        if (! $tier instanceof SchemaRegistry) {
            throw new RuntimeException("BeamSchemaRegistry tier factory for '{$source}' did not return a SchemaRegistry.");
        }

        return $tier;
    }
}
