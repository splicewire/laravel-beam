<?php

namespace Splicewire\Beam;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Facades\Beam as BeamFacade;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The Beam instance: the object the {@see BeamFacade} proxies to — the "current Beam app instance",
 * and the ONE place the table-prefix idiom lives (beam-particle-rename ticket 01, beam-facade ticket 05).
 *
 * Every Beam table name is `config('beam.core.table_prefix') . $stem`, resolved HERE and nowhere else —
 * so a retrofit host (Beam dropped into a pre-existing Laravel app that already owns `posts`/`teams`)
 * changes one config value and every Beam model `getTable()`, migration, and `->constrained(...)` follows.
 * Asserting prefix application at this single seam (not per-model) is the whole point of centralizing it.
 * Default prefix `beam_`; a retrofit host may set `''`.
 *
 * **Lookup-only** (beam-facade charting Q2b): every accessor re-reads config or a registry on each call
 * and returns a value. A call site that needs a realm, an actor, or a tenant still receives it as an
 * ARGUMENT — there is no ambient `currentRealm()`-style state here, and the binding is registered
 * `scoped()` in {@see BeamServiceProvider} so that if ambient state ever does arrive it cannot leak
 * between requests or queue jobs under Octane.
 *
 * It holds the CONTAINER and nothing else. Collaborators resolve per call, deliberately: {@see ParticleWriter}
 * is bound with `bind()`, not `singleton()`, so constructor-injecting it would pin one writer for the
 * lifetime of the scope and defeat the per-call gate rebinding its own docblock advertises (a public-intake
 * host binding its own `WriteGate`).
 *
 * **The surface is CLOSED** (beam-facade ticket 12): the four methods below are the whole of it. There is
 * deliberately no `Macroable`, and no other runtime extension point — this is not an omission to "fix".
 * A sibling `laravel-beam-*` package extends Beam the way every sibling already does, by registering into
 * a core-owned registry ({@see BeamInstallManifest}, {@see BeamDoctorManifest}) behind an
 * `$app->bound(...)` guard, which is safe by construction in a way macros are not.
 * Reopening that needs a sibling capability which clears the bar
 * AND cannot be expressed as a facade or a typed accessor.
 */
class BeamManager
{
    public function __construct(private Container $container) {}

    /**
     * The prefixed table name for a bare Beam table stem — `beam_particles`, `beam_schemas`, … under the
     * default, or `<host_prefix>particles` when a retrofit host overrides `beam.core.table_prefix`.
     */
    public function table(string $name): string
    {
        return $this->tablePrefix().$name;
    }

    /** The configured Beam table prefix (default `beam_`). */
    public function tablePrefix(): string
    {
        return (string) config('beam.core.table_prefix', 'beam_');
    }

    /**
     * The table name a SIBLING package's config key declares, falling back to this seam's prefixed stem —
     * the collapsed form of `config($configKey, Beam::table($stem))` (beam-facade ticket 04, the census's
     * one composed accessor: 12 authored sites across 3 repos).
     *
     * Argument order matches the call it replaces, deliberately: transposing the pair would silently yield
     * a WRONG table name, which is precisely the failure class this seam exists to prevent.
     */
    public function tableFor(string $configKey, string $stem): string
    {
        return (string) config($configKey, $this->table($stem));
    }

    /**
     * Write a payload through the beam-core write pipeline, returning the persisted model.
     *
     * A **seam verb** (beam-facade ticket 04): a verb earns a place on the surface only where the thing it
     * fronts is the single canonical path for an idiom, and {@see ParticleWriter}'s own docblock declares it
     * "the one path every write surface rides".
     *
     * Deliberately a **variadic forward**, not a five-parameter mirror: the real signature lives in exactly
     * one place ({@see ParticleWriter::write()}), so it cannot drift, and PHP 8.1+ collects named arguments
     * into the variadic and spreads them back correctly — which matters, because authored call sites pass
     * `after:` by name.
     *
     * @param  mixed  ...$args  forwarded verbatim to {@see ParticleWriter::write()}
     */
    public function write(mixed ...$args): Model
    {
        return $this->container->make(ParticleWriter::class)->write(...$args);
    }
}
