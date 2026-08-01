<?php

namespace Splicewire\Beam\Schema;

use Schemastud\DataSchemas\Contracts\EnumeratesVersions;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

// Note: no SchemaId strip here — the reconcile protocol passes the record TYPE (already a
// stem), not a versioned id, so re-deriving a stem would wrongly drop the last name segment.

/**
 * Beam-core's DEFAULT {@see SchemaTargetResolver}: resolve a stem's target purely
 * from the {@see SchemaRegistry} — `latest(stem)` by default, or the explicit
 * `$version` — with NO system-vs-tenant policy. This is the plain schema-record
 * case: every record type is a registered schema stem whose current is the highest
 * registered version.
 *
 * A richer host (e.g. `splicewire-app`, whose `TargetSchemaResolver` projects a live
 * system Data class for "current" and only consults the registry for prior/tenant
 * versions) OVERRIDES this by binding its own implementation of the port. Beam ships
 * this so a headless beam app gets a working migrate-on-read out of the box.
 */
class RegistrySchemaTargetResolver implements SchemaTargetResolver
{
    public function __construct(protected SchemaRegistry $registry) {}

    /**
     * The registered artifact for `$recordType` (a schema stem) at `$version` (or the
     * latest registered version when null). Empty array when the stem has no registered
     * version — a total, non-throwing "no target".
     *
     * `$recordType` is the record TYPE the reconcile protocol resolves versions for — an
     * already-stemmed `<base>/<name>`, NOT a versioned `$id`. It is used verbatim as the
     * stem; the trailing version is appended to address the concrete artifact.
     *
     * @return array<string, mixed>
     */
    public function targetFor(string $recordType, ?int $version = null): array
    {
        $resolved = $version ?? $this->latestVersion($recordType);
        if ($resolved === null) {
            return [];
        }

        return $this->registry->get($recordType.'/'.$resolved) ?? [];
    }

    /**
     * The highest registered version integer for `$stem`, or null when none are
     * registered (or the registry cannot enumerate versions).
     */
    protected function latestVersion(string $stem): ?int
    {
        if (! $this->registry instanceof EnumeratesVersions) {
            return null;
        }

        $versions = $this->registry->versionsFor($stem);

        return $versions === [] ? null : max($versions);
    }
}
