<?php

namespace Splicewire\Beam\Realm;

use Schemastud\Frame\Registry\ResourceDefinition;

/**
 * A partial, per-realm PRESENTATION overlay for one resource (RDU-03).
 *
 * A realm may present the SAME resource differently — a different label, group, form, layout, or a
 * read-only gate — WITHOUT the resource declaration ever naming a realm (ADR-0156: declarations name no
 * realm; frame stays agnostic). This is the overlay behind the `?realm` seam: a bag of the PRESENTATION
 * fields only, each nullable. A non-null field OVERLAYS the base {@see ResourceDefinition}; a null field
 * INHERITS the base value. No runtime fields live here — `model`, `data`, and hooks NEVER vary by realm
 * (only how a resource is *presented* does), so they are deliberately absent.
 *
 * `readOnly` is the one derived field: it is not carried on {@see ResourceDefinition} directly — it maps
 * to the three write gates. A non-null `readOnly` sets `creatable`/`editable`/`deletable` to `!readOnly`,
 * except where the overlay ALSO names an explicit `editable`/`deletable` (those win). This mirrors the
 * declaration's own `ParticleResource::toResourceDefinition()` derivation.
 *
 * Merged onto a base via {@see mergeInto()} (which composes {@see ResourceDefinition::withOverrides()} —
 * frame's agnostic copy-wither; named `withOverrides` because the spatie `Data` base reserves `with()`).
 * The realm concept never enters frame: frame is handed a finished {@see ResourceDefinition}.
 */
class RealmResourceOverride
{
    /**
     * @param  string|null  $label  nav label
     * @param  string|null  $group  nav group
     * @param  string|null  $icon  nav icon
     * @param  string|null  $section  nav section (host-nav join key)
     * @param  int|null  $navOrder  nav order within the section
     * @param  string|null  $routeName  generated-leaf route name
     * @param  'enriched'|'bare'|null  $form  per-resource default form mode
     * @param  'single'|'subnav'|'master-detail'|null  $layout  inner-layout grammar
     * @param  class-string|null  $editData  rare escape-hatch edit DTO
     * @param  string|null  $policy  ability/policy key
     * @param  class-string|null  $query  data-filters query class
     * @param  bool|null  $readOnly  read-only gate — maps to `creatable`/`editable`/`deletable` = `!readOnly`
     * @param  bool|null  $deletable  explicit destroy gate (wins over `readOnly`)
     * @param  bool|null  $editable  explicit in-place-edit gate (wins over `readOnly`)
     * @param  bool|null  $showable  per-record detail gate
     */
    public function __construct(
        public ?string $label = null,
        public ?string $group = null,
        public ?string $icon = null,
        public ?string $section = null,
        public ?int $navOrder = null,
        public ?string $routeName = null,
        public ?string $form = null,
        public ?string $layout = null,
        public ?string $editData = null,
        public ?string $policy = null,
        public ?string $query = null,
        public ?bool $readOnly = null,
        public ?bool $deletable = null,
        public ?bool $editable = null,
        public ?bool $showable = null,
    ) {}

    /**
     * Build an overlay from a config fragment — the `['<field>' => <value>]` map under
     * `frame.realm_resource_overrides.<realm>.<key>`. Unknown keys are ignored (forward-compatible);
     * only the known presentation fields are read.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function fromArray(array $fields): self
    {
        return new self(
            label: $fields['label'] ?? null,
            group: $fields['group'] ?? null,
            icon: $fields['icon'] ?? null,
            section: $fields['section'] ?? null,
            navOrder: $fields['navOrder'] ?? null,
            routeName: $fields['routeName'] ?? null,
            form: $fields['form'] ?? null,
            layout: $fields['layout'] ?? null,
            editData: $fields['editData'] ?? null,
            policy: $fields['policy'] ?? null,
            query: $fields['query'] ?? null,
            readOnly: $fields['readOnly'] ?? null,
            deletable: $fields['deletable'] ?? null,
            editable: $fields['editable'] ?? null,
            showable: $fields['showable'] ?? null,
        );
    }

    /**
     * Merge this overlay onto a base {@see ResourceDefinition} and return the immutable copy. Every
     * non-null field replaces the base value; a null field inherits. `readOnly`, when non-null, derives
     * the three write gates (`!readOnly`), yielding to any explicit `editable`/`deletable` in this same
     * overlay. Delegates the actual copy to frame's agnostic {@see ResourceDefinition::withOverrides()}.
     */
    public function mergeInto(ResourceDefinition $base): ResourceDefinition
    {
        $gate = $this->readOnly === null ? null : ! $this->readOnly;

        return $base->withOverrides(
            query: $this->query,
            editData: $this->editData,
            policy: $this->policy,
            form: $this->form,
            layout: $this->layout,
            creatable: $gate,
            deletable: $this->deletable ?? $gate,
            editable: $this->editable ?? $gate,
            showable: $this->showable,
            label: $this->label,
            group: $this->group,
            icon: $this->icon,
            section: $this->section,
            navOrder: $this->navOrder,
            routeName: $this->routeName,
        );
    }
}
