<?php

namespace Splicewire\Beam\Schema;

use Closure;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
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
#[IsRegistry(
    root: 'schemas.sources',
    of: 'package-contributed schema-source tier factories composed into BeamSchemaRegistry (JN-15)',
    arity: RegistryArity::RunAll,
    entryType: Closure::class,
    onDuplicate: OnDuplicate::Supersede,
    note: 'Registration order is NOT resolution order. `config(beam.core.schema.sources)` decides '
        .'precedence; contributed keys the config does not name are appended at the lowest. The host\'s '
        .'own binding-side map wins over every registration here.',
    order: 11,
)]
/**
 * @implements Registry<Closure(): SchemaRegistry>
 */
class SchemaSources implements Registry
{
    /** @var BasicRegistry<Closure(): SchemaRegistry> key → lazy tier factory, in registration order */
    protected BasicRegistry $store;

    public function __construct()
    {
        $this->store = BasicRegistry::for($this);
    }

    /**
     * Contribute (or override) a source tier. Last registration per key wins among packages;
     * the host's own binding-side map wins over all of them.
     *
     * @param  Closure(): SchemaRegistry  $factory
     */
    public function register(RegistryKey|string $key, mixed $factory = null, ?string $by = null, ?string $ability = null): static
    {
        $this->store->register($key, $factory, $by, $ability);

        return $this;
    }

    public function has(RegistryKey|string $key): bool
    {
        return $this->store->has($key);
    }

    /**
     * Build the registered tier for a key, or null when nothing is registered under it.
     *
     * ⚠️ This DIVERGES from {@see Registry::resolve()} in two ways, both deliberate and both
     * pre-existing. It returns the BUILT tier rather than the stored entry (the entry is a lazy
     * factory, and every caller wants the thing it builds), and it answers `null` on a miss where
     * the contract throws — i.e. it is `tryResolve()` semantics under the `resolve()` name.
     * Renaming it would break every caller for no gain; {@see tryFactory()} exposes the raw entry
     * for anything that genuinely wants the contract's reading.
     */
    public function resolve(RegistryKey|string $key): ?SchemaRegistry
    {
        $factory = $this->store->tryResolve($key);

        return $factory instanceof Closure ? $factory() : null;
    }

    /** The stored factory itself — the contract's reading of `resolve`, under a name that cannot collide. */
    public function tryFactory(RegistryKey|string $key): ?Closure
    {
        $factory = $this->store->tryResolve($key);

        return $factory instanceof Closure ? $factory : null;
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store->tryResolve($key);
    }

    /** @return array<string, mixed> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store->keys();
    }

    public function unfiltered(): Registry
    {
        return $this->store->unfiltered();
    }

    /**
     * @return array<string, Closure(): SchemaRegistry>
     */
    public function factories(): array
    {
        $factories = [];

        foreach ($this->store->keys() as $key) {
            $factory = $this->store->resolve($key);

            if ($factory instanceof Closure) {
                $factories[$this->bareKey($key)] = $factory;
            }
        }

        return $factories;
    }

    /**
     * The key as callers spell it, with the declared root stripped.
     *
     * `keys()` answers with ABSOLUTE `RegistryKey`s while `factories()` and `orderedSources()` both
     * key by the bare source name and are compared against config values — pass 1's recipe amendment
     * 4, where a relative-vs-absolute mismatch compiles, passes and makes the index read the wrong
     * thing.
     */
    protected function bareKey(RegistryKey|string $key): string
    {
        $prefix = 'schemas.sources.';
        $rendered = (string) $key;

        return str_starts_with($rendered, $prefix) ? substr($rendered, strlen($prefix)) : $rendered;
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
        return array_values(array_unique([...$configured, ...array_keys($this->factories())]));
    }
}
