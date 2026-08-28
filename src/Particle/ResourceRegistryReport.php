<?php

namespace Splicewire\Beam\Particle;

use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Splicewire\Beam\Frame\DefaultParticleResourceHandlerResolver;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Throwable;

/**
 * A read-only projection of the whole {@see ParticleResourceRegistry} — one row per registered
 * declaration, carrying what it SAYS about itself beside what its backing can actually DO, and naming
 * the two where they disagree.
 *
 * ## Why this is a report and not a UI
 *
 * The obvious version of this was an operator screen listing every resource. It cannot be built safely
 * at that tier: the host's nav gate (`FrameResourcesInvocable::resourceViewable()` at
 * `~/Herd/splicewire-app`) is what its own docblock calls *secure-by-omission* — a model-less resource
 * skips `viewAny` entirely — so a surface enumerating every registered key either reimplements that
 * gate or discloses the schema surface of resources the viewer is denied. A report read from a shell,
 * by an operator who already holds the host, answers the same question and gates nothing it should not.
 *
 * ## Capability is read from the backing, never from a flag
 *
 * Every capability column is an `instanceof` against the `backing:` slot, decided STATICALLY through
 * {@see BackingResolver::hasCapability()} — the same predicate registration uses. Nothing here resolves
 * a backing out of the container, so building a report never runs a backing's constructor and never
 * touches a database.
 *
 * The one exception is deliberate and isolated: {@see ParticleResource::modelClass()} resolves, because
 * {@see BacksModel::modelClass()} is a method and there is no way to
 * ask a class what it will answer. A backing whose construction throws yields a null model rather than
 * taking the report down — a reader that cannot survive one bad declaration cannot report on it.
 *
 * ## What "disagreement" means here, and what it deliberately does not
 *
 * A disagreement is **intent exceeding capability** — the declaration opening an affordance its backing
 * cannot honour. The reverse (a writing backing declared `readOnly`) is not a disagreement: narrowing is
 * the mechanism working, and flagging it would bury the defects under every read-only resource in the
 * estate.
 *
 * ⚠️ The write disagreements below are **structurally unreachable through `register()`**, which already
 * refuses them ({@see BackingResolver::assertAffordancesWithinCapability()}). They are still computed,
 * and a non-zero reading is worth more than the check costs: it means something populated the keyspace
 * around this registry's own `register()`. The disagreements that DO occur in practice are the read-side
 * ones, which nothing validates at registration.
 */
class ResourceRegistryReport
{
    public function __construct(
        private ParticleResourceRegistry $registry,
        private ?FrameResourceHandlerResolver $handlers = null,
        private ?BackingResolver $backings = null,
    ) {
        $this->backings ??= new BackingResolver;
    }

    /**
     * WHICH resolver answered the handler column, or null when none is bound.
     *
     * Worth surfacing beside the rows rather than inferring from them, because the two interesting
     * readings are indistinguishable at row level. A host may bind its own resolver (its handler names
     * then vary per key), or bind nothing and take beam's OOTB
     * {@see DefaultParticleResourceHandlerResolver}, which reads each declaration's `handler:` slot and
     * falls back to the one generic handler. That is a fact a reader should be handed, not asked to
     * deduce from 41 near-identical cells.
     *
     * ⚠️ This used to record that the flagship built an 18-entry map in `App\Frame\FrameResourceRegistry`
     * and never bound it to this port, so the map was reachable only from the host's own controller while
     * Frame's socket saw the default. Both halves are now dead: the map is dissolved onto the `handler:`
     * slot, and the flagship binds nothing. `numero` and `schemastud` are the estate's only host-bound
     * resolvers today.
     *
     * @return class-string|null
     */
    public function resolvedBy(): ?string
    {
        return $this->handlers === null ? null : $this->handlers::class;
    }

    /**
     * Every registered declaration, registration order, projected into a row.
     *
     * @return list<ResourceRegistryRow>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->registry->all() as $resource) {
            $rows[] = $this->row($resource);
        }

        return $rows;
    }

    /**
     * The rows, narrowed.
     *
     * @param  string|null  $realm  membership filter — {@see ParticleResourceRegistry::realmsFor()}
     * @param  string|null  $section  nav-section filter; the literal `none` selects the resources that
     *                                opt OUT of nav, which is the population this report exists for and
     *                                is otherwise unaddressable (a null section cannot be typed as a
     *                                CLI option value)
     * @return list<ResourceRegistryRow>
     */
    public function filtered(?string $realm = null, ?string $section = null, bool $disagreementsOnly = false): array
    {
        $rows = $this->rows();

        if ($realm !== null) {
            $rows = array_filter($rows, fn (ResourceRegistryRow $row) => in_array($realm, $row->realms, true));
        }

        if ($section !== null) {
            $rows = array_filter($rows, fn (ResourceRegistryRow $row) => $section === 'none'
                ? $row->section === null
                : $row->section === $section);
        }

        if ($disagreementsOnly) {
            $rows = array_filter($rows, fn (ResourceRegistryRow $row) => $row->disagreements !== []);
        }

        return array_values($rows);
    }

    private function row(ParticleResource $resource): ResourceRegistryRow
    {
        $backing = $resource->backing;

        $streams = $this->backings->hasCapability($backing, StreamsRecords::class);
        $queries = $this->backings->hasCapability($backing, QueriesRecords::class);
        $resolves = $this->backings->hasCapability($backing, ResolvesRecord::class);
        $writes = $this->backings->hasCapability($backing, WritesRecords::class);

        // The declaration's own affordances, with the nullable ones RESOLVED the way
        // `toResourceDefinition()` resolves them — a `null` editable follows the create gate, so
        // reporting the raw null would understate what the resource actually opens.
        $creatable = ! $resource->readOnly;
        $editable = $resource->editable ?? $creatable;
        $deletable = $resource->deletable ?? $creatable;

        return new ResourceRegistryRow(
            key: $resource->key,
            label: $resource->label,
            realms: $this->registry->realmsFor($resource->key),
            section: $resource->section,
            framed: $resource->isFramed(),
            backing: is_string($backing) ? $backing : $backing::class,
            model: $this->modelFor($resource),
            handler: $this->handlerFor($resource->key),
            streams: $streams,
            queries: $queries,
            resolves: $resolves,
            writes: $writes,
            readOnly: $resource->readOnly,
            creatable: $creatable,
            editable: $editable,
            deletable: $deletable,
            showable: $resource->showable,
            filterable: $resource->filterable,
            policy: $resource->policy,
            disagreements: $this->disagreements($resource, $streams, $queries, $resolves, $writes, $creatable, $editable, $deletable),
        );
    }

    /**
     * Intent that exceeds capability, one short phrase per finding.
     *
     * @return list<string>
     */
    private function disagreements(
        ParticleResource $resource,
        bool $streams,
        bool $queries,
        bool $resolves,
        bool $writes,
        bool $creatable,
        bool $editable,
        bool $deletable,
    ): array {
        $found = [];

        // A resource is an index before it is anything else; a backing that streams nothing cannot
        // serve the one read every transport makes.
        if (! $streams) {
            $found[] = 'listed but backing has no StreamsRecords';
        }

        // `filterable` DEFAULTS to true, so this is the read-side disagreement that actually occurs:
        // the declaration says the index rides the data-filters builder, and the backing yields no
        // composable Builder for it to ride.
        if ($resource->filterable && ! $queries) {
            $found[] = 'filterable but backing has no QueriesRecords';
        }

        // A detail read needs something to resolve one record against. An Eloquent-backed resource
        // resolves through the declaration's own read projection rather than `ResolvesRecord`, which is
        // why `QueriesRecords` clears this too — flagging every model-backed resource would make the
        // column worthless.
        if ($resource->showable && ! $resolves && ! $queries) {
            $found[] = 'showable but backing can neither ResolveRecord nor query';
        }

        // The write axis. Unreachable through `register()`; see the class docblock.
        foreach (['creatable' => $creatable, 'editable' => $editable, 'deletable' => $deletable] as $affordance => $open) {
            if ($open && ! $writes) {
                $found[] = $affordance.' but backing has no WritesRecords';
            }
        }

        return $found;
    }

    /**
     * The model a declaration backs, or null — swallowing a construction failure on purpose.
     *
     * Unlike every capability column this RESOLVES the backing, because `modelClass()` is a method. A
     * backing whose constructor needs a tenant connection this shell has not entered would otherwise
     * abort the whole report at one row.
     */
    private function modelFor(ParticleResource $resource): ?string
    {
        try {
            return $resource->modelClass();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Which handler serves this key — the column that makes the fall-through population visible.
     *
     * The handler map is HOST-owned (the resolver is a Frame port a host binds), so this is a fact about
     * the host and never about the declaration. No resolver bound, or a resolver that throws for this
     * key, reports null rather than failing: a report that cannot describe a resource is worse than one
     * that says it does not know.
     */
    private function handlerFor(string $key): ?string
    {
        if ($this->handlers === null) {
            return null;
        }

        try {
            return $this->handlers->handlerFor($key)::class;
        } catch (Throwable) {
            return null;
        }
    }
}
