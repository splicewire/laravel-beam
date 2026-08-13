<?php

namespace Splicewire\Beam\Schema;

use Closure;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * The boot-time schema-SOURCE factory registry (ADR-0192 §5): a container singleton any
 * package's service provider pushes `(key, factory)` pairs into, so a package can contribute
 * a {@see BeamSchemaRegistry} tier without the host app being edited — the same
 * self-registration pattern as {@see BeamInstallManifest} et al.
 * beam-core never learns a contributor's name.
 *
 * The host's `SchemaRegistry` binding composes this registry's factories with its own
 * app-local map (app factories win on a key collision — a host may override a contributed
 * tier) and orders sources via {@see orderedSources()}: `config('beam.core.schema.sources')`
 * carries the explicit order; contributed keys the config does not name are APPENDED after
 * it, in registration order — resolvable by default at the LOWEST precedence, with the config
 * list remaining the one place ordering is decided (list a contributed key there to place it).
 *
 * Ordering is load-bearing (ADR-0192 §4): reads are first-hit-wins, so the `fleet` tier —
 * registered here by beam-core itself, ahead of `db` in the default source list — makes fleet
 * vocabulary artifacts NON-shadowable by a tenant, while ordinary content schemas keep their
 * db-over-file tenant-override behavior.
 */
class SchemaSources
{
    /** @var array<string, Closure(): SchemaRegistry> key → lazy tier factory, in registration order */
    protected array $factories = [];

    /**
     * Contribute (or override) a source tier. Last registration per key wins among packages;
     * the host's own binding-side map wins over all of them.
     *
     * @param  Closure(): SchemaRegistry  $factory
     */
    public function register(string $key, Closure $factory): static
    {
        $this->factories[$key] = $factory;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->factories[$key]);
    }

    /** @return array<string, Closure(): SchemaRegistry> */
    public function factories(): array
    {
        return $this->factories;
    }

    /**
     * The effective ordered source list: the configured keys first (explicit order), then any
     * contributed keys the config does not name, appended in registration order.
     *
     * @param  array<int, string>  $configured
     * @return array<int, string>
     */
    public function orderedSources(array $configured): array
    {
        return array_values(array_unique([...$configured, ...array_keys($this->factories)]));
    }
}
