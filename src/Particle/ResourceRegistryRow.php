<?php

namespace Splicewire\Beam\Particle;

/**
 * One registered particle resource, as {@see ResourceRegistryReport} sees it: its declared INTENT, its
 * backing's CAPABILITY, and the places the two disagree.
 *
 * A plain immutable value rather than a Data class on purpose — nothing here crosses a wire. It is read
 * by a console command and by an advisory doctor audit, both of which run inside the host, so it is not
 * a boundary shape and carries no particle declaration (AGENTS.md, particle doctrine: the doctrine
 * governs shapes that cross a boundary).
 */
class ResourceRegistryRow
{
    /**
     * @param  list<string>  $realms  membership, explicit rung first — {@see ParticleResourceRegistry::realmsFor()}
     * @param  string|null  $section  the host sitemap section it auto-attaches into; null ⇒ it appears in
     *                                no primary nav, which is the DEFAULT and not a backlog
     * @param  class-string|null  $model  null is legal and common — a backing need not back one model
     * @param  class-string|null  $handler  null ⇒ no `FrameResourceHandlerResolver` is bound on this host
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
        public bool $readOnly,
        public bool $creatable,
        public bool $editable,
        public bool $deletable,
        public bool $showable,
        public bool $filterable,
        public ?string $policy,
        public array $disagreements,
    ) {}

    /**
     * The capability set, shortest legible spelling — `list` / `query` / `show` / `write`, present only
     * when the backing actually implements the interface behind it.
     */
    public function capabilities(): string
    {
        $names = array_keys(array_filter([
            'list' => $this->streams,
            'query' => $this->queries,
            'show' => $this->resolves,
            'write' => $this->writes,
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
            'filter' => $this->filterable,
        ]));

        return $names === [] ? 'read-only' : implode(' ', $names);
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
            ],
            'intent' => [
                'readOnly' => $this->readOnly,
                'creatable' => $this->creatable,
                'editable' => $this->editable,
                'deletable' => $this->deletable,
                'showable' => $this->showable,
                'filterable' => $this->filterable,
                'policy' => $this->policy,
            ],
            'disagreements' => $this->disagreements,
        ];
    }
}
