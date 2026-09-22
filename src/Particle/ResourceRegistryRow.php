<?php

namespace Splicewire\Beam\Particle;

use Splicewire\Beam\Data\ResourceRegistry\ResourceRegistryEntryData;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Registry\ResourceRegistryBacking;

/**
 * One registered particle resource, as {@see ResourceRegistryReport} sees it: its declared INTENT, its
 * backing's CAPABILITY, and the places the two disagree.
 *
 * A plain immutable value rather than a Data class on purpose — the row itself never crosses a wire. A
 * console command and an advisory doctor audit read it inside the host; the operator Resources area
 * reads it too, but what that area SERVES is {@see ResourceRegistryEntryData},
 * the declared projection {@see ResourceRegistryBacking} builds from
 * this row after the viewer's visibility filter has run. So the boundary shape is declared where it
 * crosses (AGENTS.md, particle doctrine), and this value stays free to carry host-only facts.
 */
class ResourceRegistryRow
{
    /**
     * @param  list<string>  $realms  membership, explicit rung first — {@see ParticleResourceRegistry::realmsFor()}
     * @param  string|null  $section  the host sitemap section it auto-attaches into; null ⇒ it appears in
     *                                no primary nav, which is the DEFAULT and not a backlog
     * @param  class-string|null  $model  null is legal and common — a backing need not back one model
     * @param  class-string|null  $handler  null ⇒ no `FrameResourceHandlerResolver` is bound on this host
     * @param  bool  $vocabulary  the backing declares its own filter vocabulary
     *                            ({@see DeclaresFilterVocabulary}) — the
     *                            streams-only way to have a panel
     * @param  list<string>  $disagreements  empty ⇒ intent stays within capability
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $realms,
        public ?string $section,
        public bool $framed,
        public string $backing,
        public ?string $model,
        public ?string $handler,
        public bool $streams,
        public bool $queries,
        public bool $resolves,
        public bool $writes,
        public bool $vocabulary,
        public bool $readOnly,
        public bool $creatable,
        public bool $editable,
        public bool $deletable,
        public bool $showable,
        public bool $filters,
        public ?string $policy,
        public array $disagreements,
    ) {}

    /**
     * The capability set, shortest legible spelling — `list` / `query` / `show` / `write` / `filters`,
     * present only when the backing actually implements the interface behind it.
     */
    public function capabilities(): string
    {
        $names = array_keys(array_filter([
            'list' => $this->streams,
            'query' => $this->queries,
            'show' => $this->resolves,
            'write' => $this->writes,
            'filters' => $this->vocabulary,
        ]));

        return $names === [] ? '—' : implode(' ', $names);
    }

    /**
     * The declared affordances, same spelling discipline as {@see capabilities()} so the two columns can
     * be read against each other at a glance. `read-only` is stated positively rather than as an absence,
     * because a row with no affordances at all is the ordinary shape of a machine-authored resource and
     * an empty cell reads like missing data.
     */
    public function intent(): string
    {
        $names = array_keys(array_filter([
            'create' => $this->creatable,
            'edit' => $this->editable,
            'delete' => $this->deletable,
            'show' => $this->showable,
        ]));

        return $names === [] ? 'read-only' : implode(' ', $names);
    }

    /**
     * What this resource can EFFECTIVELY do: capability ∩ intent, one boolean per surface verb.
     *
     * The backing's capability is the ceiling and the declared flags only narrow it (particle doctrine,
     * "Capability is the CEILING; the affordance flags may only narrow"), so every verb is an AND of the
     * two — never either one alone. That is the whole reason this exists beside {@see capabilities()} and
     * {@see intent()}: a surface that offered `edit` off `editable` would draw a pencil the server 405s the
     * moment the backing cannot write, and one that offered it off `writes` would draw a pencil on every
     * resource declared `readOnly`.
     *
     *  - `list`   — the backing streams records. There is no declared flag to narrow a list: a registered
     *               resource IS an index.
     *  - `show`   — `showable`, and a record can be resolved one at a time: `ResolvesRecord`, or a query
     *               the declaration's read projection runs over (`QueriesRecords`), the same two routes
     *               {@see ResourceRegistryReport}'s disagreement column accepts.
     *  - `create` / `edit` / `delete` — the resolved flag AND `WritesRecords`.
     *  - `filter` — a panel exists: the resolved filter definition or backing vocabulary contains controls.
     *
     * ⚠️ These are the declaration's answer, not the viewer's. Who may do each is still asked server-side,
     * per request, by the transport's own authorizers; a surface that reads this to HIDE a button has
     * decided nothing about whether the request would be refused.
     *
     * @return array{list: bool, show: bool, create: bool, edit: bool, delete: bool, filter: bool}
     */
    public function affordances(): array
    {
        return [
            'list' => $this->streams,
            'show' => $this->showable && ($this->resolves || $this->queries),
            'create' => $this->creatable && $this->writes,
            'edit' => $this->editable && $this->writes,
            'delete' => $this->deletable && $this->writes,
            'filter' => $this->filters,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'realms' => $this->realms,
            'section' => $this->section,
            'framed' => $this->framed,
            'backing' => $this->backing,
            'model' => $this->model,
            'handler' => $this->handler,
            'capabilities' => [
                'streams' => $this->streams,
                'queries' => $this->queries,
                'resolves' => $this->resolves,
                'writes' => $this->writes,
                'vocabulary' => $this->vocabulary,
            ],
            'intent' => [
                'readOnly' => $this->readOnly,
                'creatable' => $this->creatable,
                'editable' => $this->editable,
                'deletable' => $this->deletable,
                'showable' => $this->showable,
                'policy' => $this->policy,
            ],
            'affordances' => $this->affordances(),
            'disagreements' => $this->disagreements,
        ];
    }
}
