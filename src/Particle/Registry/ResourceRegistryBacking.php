<?php

namespace Splicewire\Beam\Particle\Registry;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\AuthenticatedActor;
use Splicewire\Beam\Authorization\ModelLessReadPosture;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Data\ResourceRegistry\ResourceRegistryEntryData;
use Splicewire\Beam\Doctor\ModelLessReadGateAudit;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ResourceRegistryReport;
use Throwable;

/**
 * The particle resource registry as a resource — every registered declaration the acting principal may
 * LIST, one {@see ResourceRegistryEntryData} each. The backing of the operator Resources area
 * (otb-ui-frontier-sidebar DESIGN-02).
 *
 * ## Model-less and read-only, and it says so by what it implements
 *
 * {@see StreamsRecords} (the list), {@see ResolvesRecord} (one entry by key) and
 * {@see DeclaresFilterVocabulary} (its facets). No `QueriesRecords` — there is no table — and no
 * `WritesRecords`: a registry entry is a DECLARATION in code, and nothing a request sends can change one.
 * Capability being the ceiling, a host that declared this resource creatable would be refused at
 * registration.
 *
 * ## The row filter IS the gate, and it runs here rather than being trusted to a caller
 *
 * A key, a backing class and a capability set disclose a resource's schema surface, so the one read this
 * backing must not make is "every registered resource". Each row is kept only when
 * {@see ResourceVisibility::listable()} says the actor may be SHOWN the resource — the same visibility question
 * the nav collectors ask of every seat — so this area cannot become the bypass around the gate that hides a
 * resource from the rail. (The nav ALSO narrows by realm and section; the area deliberately does not, since
 * it exists for the resources no rail carries.) On a host whose area is Root-only and whose Root is a
 * `Gate::before` superuser, every row passes; the filter earns its keep the day a non-superuser may open
 * the area. A resource whose model cannot even be resolved for this request (a backing that throws on
 * construction) is dropped too: absence is the fail-closed reading of "cannot tell".
 *
 * That filter is per ROW. Whether the actor may open the AREA at all is the declaring resource's own
 * `policy:` — this backing is model-less, so its read posture is whatever the host declares
 * ({@see ResourceVisibility::readable()}), and a host that declares none is reported undeclared by
 * {@see ModelLessReadGateAudit} like any other.
 *
 * {@see resolve()} answers a key the actor may not list with NULL — a 404 — never a 403: a refused detail
 * read that says "forbidden" confirms the key exists, which is exactly what the listing withheld
 * (registry-kernel ticket 17 D5).
 *
 * ## Materialized, on purpose
 *
 * The registry is an in-memory list of tens of declarations built at boot. Filtering and paging in PHP is
 * the honest shape (the same reasoning `CollectionBacking` documents). The page cursor names the last key
 * served, over the one fixed order below, and pages both ways; a cursor naming a key this actor can no longer
 * list restarts the list rather than guessing where that key would have sorted.
 *
 * Order: section ascending with the unsectioned group LAST, then label, then key. Deliberately not
 * `navOrder`: the area is where resources that opt OUT of nav are found, and ordering it by the rail
 * would sort the population it exists for by a value it does not have.
 *
 * ## The facets it declares — and applies
 *
 * Every facet {@see filterVocabulary()} names, {@see matches()} interprets; a declared facet the backing
 * ignored would be this class's lie (`DeclaredFacet`'s own warning). Sort is NOT declared: Frame's
 * streamed index forwards only the `filter[...]` bag, so an `x-sort` here would offer an order the read
 * never sees.
 *
 *  - `search`       — substring over key, label, section, and the model and backing class names.
 *  - `section`      — one-of; `none` selects the resources declaring no section.
 *  - `realm`        — one-of; `none` selects the resources homed in no realm.
 *  - `capability`   — ALL-of: a row must implement every capability selected. Capabilities are a
 *                     conjunction by nature ("can stream AND write"); one-of would widen as you select.
 *  - `posture`      — one-of `model-backed` / `declared-ability` / `undeclared`.
 *  - `nav`          — `seated` / `unseated`.
 *  - `disagreement` — `some` / `none`.
 *
 * Picker domains are Options Sources ({@see options()}), and the section and realm domains are computed
 * from the VISIBLE rows only, for the same disclosure reason as the row filter: a section or realm name that
 * exists only on resources the actor cannot list is not offered.
 */
class ResourceRegistryBacking implements DeclaresFilterVocabulary, ResolvesRecord, StreamsRecords
{
    /** The set-facet literal for "declares no section" / "belongs to no realm" — the command's spelling. */
    public const NONE = 'none';

    public const CAPABILITIES = ['streams', 'queries', 'resolves', 'writes', 'vocabulary'];

    /**
     * The Options Source handle behind each picker facet. The options namespace is flat and shared by every
     * resource, so the handles carry the registry's prefix; the filter sub-surface enumerates only a handle
     * this backing's vocabulary names.
     */
    public const OPTIONS = [
        'section' => 'particle_resource_sections',
        'realm' => 'particle_resource_realms',
        'capability' => 'particle_resource_capabilities',
        'posture' => 'particle_resource_postures',
        'nav' => 'particle_resource_nav',
        'disagreement' => 'particle_resource_disagreements',
    ];

    /** @var list<ResourceRegistryEntryData>|null memoized for this request's backing instance */
    private ?array $entries = null;

    public function __construct(
        protected ResourceRegistryReport $report,
        protected ParticleResourceRegistry $particles,
        protected ResourceVisibility $visibility,
        protected ResourceSurfaceLocator $surfaces,
        protected ActorPort $actors,
    ) {}

    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginatorContract
    {
        $perPage = max(1, $perPage);
        $rows = array_values(array_filter(
            $this->entries(),
            fn (ResourceRegistryEntryData $entry): bool => $this->matches($entry, $filters),
        ));

        $decoded = Cursor::fromEncoded($cursor);

        if ($decoded === null) {
            // perPage + 1 so the paginator can report a following page.
            return new CursorPaginator(array_slice($rows, 0, $perPage + 1), $perPage, null, ['parameters' => ['key']]);
        }

        $index = array_search((string) $decoded->parameter('key'), array_map(fn (ResourceRegistryEntryData $entry): string => $entry->key, $rows), true);

        if ($index === false) {
            return new CursorPaginator(array_slice($rows, 0, $perPage + 1), $perPage, null, ['parameters' => ['key']]);
        }

        if ($decoded->pointsToPreviousItems()) {
            // The rows BEFORE the key, nearest last; the paginator reverses a previous-page batch back into
            // order itself, so hand it them nearest-first with one extra to say whether more lie before.
            $before = array_reverse(array_slice($rows, 0, $index));

            return new CursorPaginator(array_slice($before, 0, $perPage + 1), $perPage, $decoded, ['parameters' => ['key']]);
        }

        return new CursorPaginator(array_slice($rows, $index + 1, $perPage + 1), $perPage, $decoded, [
            'parameters' => ['key'],
        ]);
    }

    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        foreach ($this->entries() as $entry) {
            if ($entry->key === $id) {
                return new ResolvedRecord(record: $entry);
            }
        }

        return null;
    }

    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(
            DeclaredFacet::search('search')->titled('Search'),
            DeclaredFacet::set('section', options: self::OPTIONS['section'])->titled('Section'),
            DeclaredFacet::set('realm', options: self::OPTIONS['realm'])->titled('Realm'),
            DeclaredFacet::set('capability', options: self::OPTIONS['capability'])->titled('Capability'),
            DeclaredFacet::set('posture', options: self::OPTIONS['posture'])->titled('Read posture'),
            DeclaredFacet::exact('nav', options: self::OPTIONS['nav'])->titled('Nav'),
            DeclaredFacet::exact('disagreement', options: self::OPTIONS['disagreement'])->titled('Disagreement'),
        );
    }

    /**
     * One facet's option domain, narrowed by `$search` — what the Options Sources named in
     * {@see OPTIONS} answer (registered by `BeamServiceProvider::declareResourceRegistryOptions()`).
     *
     * Served as Options Sources rather than inlined into the schema because the facets panel resolves a
     * picker's options through `optionsRef` only; an inline domain declared the same facet and rendered an
     * empty picker (measured in the operator console, 2026-09-12). The section and realm domains are
     * computed from the VISIBLE rows, for the same disclosure reason as the row filter.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(string $facet, ?string $search = null): array
    {
        $domain = match ($facet) {
            'section' => [...$this->distinct(fn (ResourceRegistryEntryData $entry): array => $entry->section === null ? [] : [$entry->section]), ['value' => self::NONE, 'label' => 'Unsectioned']],
            'realm' => [...$this->distinct(fn (ResourceRegistryEntryData $entry): array => $entry->realms), ['value' => self::NONE, 'label' => 'No realm']],
            'capability' => [
                ['value' => 'streams', 'label' => 'Lists (StreamsRecords)'],
                ['value' => 'queries', 'label' => 'Queries (QueriesRecords)'],
                ['value' => 'resolves', 'label' => 'Resolves one (ResolvesRecord)'],
                ['value' => 'writes', 'label' => 'Writes (WritesRecords)'],
                ['value' => 'vocabulary', 'label' => 'Declares facets'],
            ],
            'posture' => [
                ['value' => 'model-backed', 'label' => 'Model-backed'],
                ['value' => ModelLessReadPosture::DeclaredAbility->value, 'label' => 'Model-less, gated'],
                ['value' => ModelLessReadPosture::Undeclared->value, 'label' => 'Model-less, undeclared'],
            ],
            'nav' => [
                ['value' => 'seated', 'label' => 'In a nav'],
                ['value' => 'unseated', 'label' => 'Not in any nav'],
            ],
            'disagreement' => [
                ['value' => 'some', 'label' => 'Intent exceeds capability'],
                ['value' => 'none', 'label' => 'Consistent'],
            ],
            default => [],
        };

        // The Options Sources live in data-filters' FLAT namespace, and the filter sub-surface enumerates a
        // handle for any resource whose definition does not narrow its references — so these domains are
        // reachable from mounts that never asked the area's own read gate. Ask it here: an actor who may not
        // read a resource this backing serves is offered nothing.
        if ($domain === [] || ! $this->areaReadable()) {
            return [];
        }

        $needle = mb_strtolower(trim((string) $search));

        if ($needle === '') {
            return $domain;
        }

        return array_values(array_filter(
            $domain,
            fn (array $option): bool => str_contains(mb_strtolower($option['label'].' '.$option['value']), $needle),
        ));
    }

    /**
     * May the acting principal READ some resource this class backs? The area's own gate
     * ({@see ResourceVisibility::readable()} over the declaration's `policy:`), asked for every declaration
     * whose backing is this class — which key a host registers it under is the host's choice.
     */
    private function areaReadable(): bool
    {
        $actor = $this->actor();

        foreach ($this->particles->all() as $resource) {
            if (is_string($resource->backing) && is_a($resource->backing, self::class, true)
                && $this->visibility->readable($resource, $actor)) {
                return true;
            }
        }

        return false;
    }

    private function actor(): ?Authenticatable
    {
        return AuthenticatedActor::from($this->actors);
    }

    /**
     * @param  callable(ResourceRegistryEntryData): list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private function distinct(callable $values): array
    {
        $seen = [];

        foreach ($this->entries() as $entry) {
            foreach ($values($entry) as $value) {
                $seen[$value] = true;
            }
        }

        ksort($seen);

        return array_map(fn (string $value): array => ['value' => $value, 'label' => $value], array_map('strval', array_keys($seen)));
    }

    /**
     * Every registered resource the acting principal may list, projected, in the fixed order.
     *
     * @return list<ResourceRegistryEntryData>
     */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $actor = $this->actor();

        $entries = [];

        foreach ($this->report->rows() as $row) {
            $resource = $this->particles->find($row->key);

            if ($resource === null || ! $this->listable($resource, $actor)) {
                continue;
            }

            $posture = $this->posture($resource);

            $entries[] = ResourceRegistryEntryData::fromRow(
                $row,
                $posture,
                $this->readGateFindings($resource, $posture),
                $this->surfaces->surfacesFor($row->key, $row->section),
            );
        }

        usort($entries, fn (ResourceRegistryEntryData $a, ResourceRegistryEntryData $b): int => [$a->section === null, $a->section ?? '', strtolower($a->label !== '' ? $a->label : $a->key), $a->key]
            <=> [$b->section === null, $b->section ?? '', strtolower($b->label !== '' ? $b->label : $b->key), $b->key]);

        return $this->entries = $entries;
    }

    /**
     * Does one entry satisfy the whole filter bag? Unknown keys are ignored — the bag is opaque and shared
     * with every other capability (`StreamsRecords`), so a key this backing does not declare is someone
     * else's, not an error.
     *
     * @param  array<string, mixed>  $filters
     */
    public function matches(ResourceRegistryEntryData $entry, array $filters): bool
    {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $haystack = mb_strtolower(implode(' ', array_filter([
                $entry->key, $entry->label, $entry->section, $entry->model, $entry->backing,
            ])));

            if (! str_contains($haystack, $needle)) {
                return false;
            }
        }

        $sections = $this->values($filters['section'] ?? null);

        if ($sections !== [] && ! in_array($entry->section ?? self::NONE, $sections, true)) {
            return false;
        }

        $realms = $this->values($filters['realm'] ?? null);

        if ($realms !== []) {
            $memberships = $entry->realms === [] ? [self::NONE] : $entry->realms;

            if (array_intersect($realms, $memberships) === []) {
                return false;
            }
        }

        foreach ($this->values($filters['capability'] ?? null) as $capability) {
            if (! in_array($capability, self::CAPABILITIES, true) || ! $entry->capabilities->{$capability}) {
                return false;
            }
        }

        $postures = $this->values($filters['posture'] ?? null);

        if ($postures !== [] && ! in_array($entry->posture, $postures, true)) {
            return false;
        }

        $nav = (string) ($filters['nav'] ?? '');

        if (($nav === 'seated' && ! $entry->navSeated) || ($nav === 'unseated' && $entry->navSeated)) {
            return false;
        }

        $disagreement = (string) ($filters['disagreement'] ?? '');

        if (($disagreement === 'some' && $entry->disagreements === []) || ($disagreement === 'none' && $entry->disagreements !== [])) {
            return false;
        }

        return true;
    }

    /**
     * {@see ResourceVisibility::listable()} for one declaration, failing CLOSED when its model cannot be
     * resolved for this request (a request-time backing whose constructor needs something the request lacks):
     * a row this request cannot classify is a row it must not show.
     *
     * The question is asked of a definition built HERE rather than of `toResourceDefinition()`, because that
     * projection is a frame manifest row and refuses a declaration with no `data:` class — every REST-only
     * resource that declares none (`runner-transforms`, `disclosures` at the flagship, 2026-09-12). `listable()`
     * reads only the key, the model and the declared `policy:`, so those are the fields that must be the
     * declaration's own; the rest is inert.
     */
    private function listable(ParticleResource $resource, ?Authenticatable $actor): bool
    {
        try {
            $definition = new ResourceDefinition(
                key: $resource->key,
                model: $resource->modelClass(),
                data: $resource->data ?? '',
                creatable: ! $resource->readOnly,
                query: $resource->query,
                editData: $resource->editData,
                policy: $resource->policy,
                form: $resource->form,
                nav: new NavMetadata(label: $resource->label, section: $resource->section),
            );
        } catch (Throwable) {
            return false;
        }

        return $this->visibility->listable($definition, $actor);
    }

    private function posture(ParticleResource $resource): string
    {
        return $this->visibility->posture($resource)?->value ?? 'model-backed';
    }

    /**
     * The model-less read-gate audit's reading for ONE resource — the same two findings
     * {@see ModelLessReadGateAudit} aggregates, in words an operator reads beside the row.
     *
     * @return list<string>
     */
    private function readGateFindings(ParticleResource $resource, string $posture): array
    {
        if ($posture === ModelLessReadPosture::Undeclared->value) {
            return [ModelLessReadGateAudit::CHECK.': model-less and declares no read gate — listed to every authenticated actor its realm admits (app ADR-0119 §2). Declare `policy:` with the subject-free ability a reader must hold.'];
        }

        if ($posture === ModelLessReadPosture::DeclaredAbility->value && $this->visibility->declaresPolicyClass($resource)) {
            return [ModelLessReadGateAudit::CHECK.': declares a policy CLASS where a read ability is read — every reader but a Gate::before superuser is refused. Declare the subject-free ability instead.'];
        }

        return [];
    }

    /**
     * A set facet's value: `a,b` on the wire, or an array when a caller hands one.
     *
     * @return list<string>
     */
    private function values(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(fn (mixed $value): string => trim((string) $value), $values), fn (string $value): bool => $value !== ''));
    }
}
