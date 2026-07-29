<?php

declare(strict_types=1);

namespace Splicewire\Beam\Schema;

use InvalidArgumentException;
use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;

/**
 * Composes the runtime DB-backed registry with the committed filesystem one into
 * a single resolver.
 *
 * READ resolution is DB-FIRST: a tenant's runtime-registered schema (or a frozen
 * version a runtime caller minted) wins, falling back to the host's committed code
 * artifacts. This lets a downstream tenant bring its own versioned schemas and
 * evolve them without a deploy, while code schemas remain resolvable.
 *
 * WRITE always targets the DB tier — runtime registrations are tenant-owned and
 * must not mutate the committed code artifacts on disk. The DB tier enforces the
 * same write-once immutability (idempotent on identical fingerprint, rejects a
 * differing one with SchemaRegistryConflict).
 *
 * Both tiers are injected `SchemaRegistry`s, so the ordering/policy (which tier
 * wins, which directory, which connection) is the host's binding to decide — the
 * chaining mechanism itself is host-agnostic.
 */
class ChainedSchemaRegistry implements EnumeratesVersions, SchemaRegistry
{
    public function __construct(
        protected SchemaRegistry $database,
        protected SchemaRegistry $filesystem,
    ) {}

    public function register(array $schema): void
    {
        $id = $schema['$id'] ?? null;
        if (! is_string($id) || $id === '') {
            throw new InvalidArgumentException('Cannot register a schema without an $id.');
        }

        // Runtime registrations land in the DB tier (tenant-owned, write-once).
        $this->database->register($schema);
    }

    public function get(string $id): ?array
    {
        return $this->database->get($id) ?? $this->filesystem->get($id);
    }

    public function has(string $id): bool
    {
        return $this->database->has($id) || $this->filesystem->has($id);
    }

    public function ids(): array
    {
        $ids = array_unique(array_merge($this->database->ids(), $this->filesystem->ids()));
        sort($ids);

        return $ids;
    }

    /**
     * The version integers for a stem MERGED and de-duplicated across both tiers,
     * ascending — so a stem whose versions were frozen partly in the runtime DB
     * tier and partly in the committed filesystem tier reports a complete history.
     *
     * A tier that does not implement {@see EnumeratesVersions} contributes nothing
     * (the contract delta is additive — exact-`$id` reads are untouched).
     *
     * @return array<int, int>
     */
    public function versionsFor(string $stem): array
    {
        $versions = array_merge(
            $this->database instanceof EnumeratesVersions ? $this->database->versionsFor($stem) : [],
            $this->filesystem instanceof EnumeratesVersions ? $this->filesystem->versionsFor($stem) : [],
        );

        $versions = array_values(array_unique($versions));
        sort($versions);

        return $versions;
    }
}
