<?php

declare(strict_types=1);

namespace Splicewire\Beam\Schema\Contracts;

/**
 * The beam-core port for "resolve the target schema document for a record type at
 * an optional version." The migration adapter ({@see \Splicewire\Beam\Schema\SchemaLadderMigrator})
 * depends on this port, NOT on any host class, so it can live in beam while the
 * *policy* for resolving a target stays host-owned.
 *
 * A host owns the policy behind this seam:
 *
 *  - SYSTEM schema — a code-backed Data class on the platform: the target is
 *    PROJECTED from the live class (an explicit prior version comes from the registry).
 *  - TENANT schema — a bare stem with no PHP class: the target is RESOLVED from the
 *    registry (`latest(stem)` by default, or the explicit `$version`).
 *
 * The app's `App\Schema\TargetSchemaResolver` satisfies this port with no behaviour
 * change; beam ships a registry-backed default resolver for the plain schema-record
 * case, which a richer host overrides by binding its own implementation.
 */
interface SchemaTargetResolver
{
    /**
     * The target schema document for a record type at an optional pinned version.
     *
     * `$recordType` is either a `class-string` (a system schema Data class) or a bare
     * stem string (a tenant schema). `$version`, when given, pins a specific version;
     * null means "current" (live-class projection for a system class, registry-latest
     * for a tenant stem).
     *
     * Returns an empty array when no target can be resolved (an unknown stem or
     * version), so callers can treat "no target" as a total, non-throwing case.
     *
     * @return array<string, mixed>
     */
    public function targetFor(string $recordType, ?int $version = null): array;
}
