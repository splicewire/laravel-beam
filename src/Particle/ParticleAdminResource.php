<?php

namespace Splicewire\Beam\Particle;

use Closure;
use Schemastud\Frame\Registry\ResourceDefinition;

/**
 * @deprecated (RDU-01) An interim thin alias of {@see ParticleResource}. The manifest fields and the
 * {@see ResourceDefinition} projection this class used to own have moved UP onto {@see ParticleResource}
 * (RDU-01, the expand half of the expand→contract retire); this subclass now only forwards its
 * constructor, so every existing `new ParticleAdminResource(...)` registration/import compiles and
 * behaves IDENTICALLY. RDU-07 (the contract half) will delete this class and the `#[AdminResource]`
 * attribute. Prefer {@see ParticleResource} directly in new code.
 *
 * An admin-editable particle resource: a {@see ParticleResource} carrying the editor/manifest concerns
 * (nav, layout, edit shape, policy, form mode) and a projection into Frame's agnostic contract
 * ({@see ResourceDefinition}). The honest subtype relation held — every admin resource IS a particle
 * resource + a manifest — which is why the two classes have now merged.
 *
 * Scope: **model-backed** resources only. A union/aggregate surface (e.g. a review queue) is not a
 * particle resource — it has no single model and no write path — and stays a Frame `UnionSource` (the gap
 * policy: widen a Frame port, don't subclass this).
 */
class ParticleAdminResource extends ParticleResource
{
    /**
     * Historically this constructor placed the manifest params (label, form, …) DIRECTLY after `data`.
     * The merged {@see ParticleResource} appends them AFTER the runtime hooks instead, so this subclass
     * keeps the old positional order and forwards by NAME — preserving every existing call site
     * (positional or named) unchanged.
     *
     * @param  string  $label  nav label (required — an admin resource is navigable)
     * @param  string  $form  per-resource default form mode: 'enriched' | 'bare'
     * @param  class-string|null  $editData  rare escape-hatch edit DTO (input-shape divergence)
     * @param  string|null  $policy  ability/policy key the injected can() resolves against
     * @param  class-string|null  $query  data-filters query class the ListShell facets bar rides (manifest)
     * @param  string|null  $group  nav group heading
     * @param  string|null  $icon  nav icon key
     * @param  string|null  $section  host sitemap section this resource auto-attaches into; null = not in primary nav
     * @param  int|null  $navOrder  placement within the section
     * @param  string|null  $routeName  stable route identity a host binds the generated leaf under
     * @param  string|null  $layout  inner-layout grammar ('single'|'subnav'|'master-detail')
     * @param  bool  $readOnly  a machine-authored / view-only resource — no create/edit/delete through Frame (ADR-0156 §83)
     * @param  bool|null  $deletable  Frame destroy gate, INDEPENDENT of $readOnly (ADR-0156 §83 delete-independent widening)
     * @param  bool|null  $editable  Frame update gate, INDEPENDENT of $readOnly (ADR-0156 §83 edit-independent widening)
     * @param  bool  $showable  Frame per-record detail gate, INDEPENDENT of $readOnly and $editable (F04 show-independent widening)
     *
     * The remaining params are {@see ParticleResource}'s runtime core — including the optional `$scope`
     * list/subject-resolution closure (forwarded to the parent).
     */
    public function __construct(
        string $key,
        string $model,
        ?string $data = null,
        string $label = '',
        ?string $input = null,
        array $includes = [],
        bool $filterable = true,
        int $perPage = 20,
        ?Closure $prepare = null,
        ?Closure $afterWrite = null,
        ?Closure $project = null,
        ?Closure $scope = null,
        string $form = 'bare',
        ?string $editData = null,
        ?string $policy = null,
        ?string $query = null,
        ?string $group = null,
        ?string $icon = null,
        ?string $section = null,
        ?int $navOrder = null,
        ?string $routeName = null,
        ?string $layout = null,
        bool $readOnly = false,
        ?bool $deletable = null,
        ?bool $editable = null,
        bool $showable = true,
    ) {
        parent::__construct(
            key: $key,
            model: $model,
            data: $data,
            input: $input,
            includes: $includes,
            filterable: $filterable,
            perPage: $perPage,
            prepare: $prepare,
            afterWrite: $afterWrite,
            project: $project,
            scope: $scope,
            label: $label,
            form: $form,
            editData: $editData,
            policy: $policy,
            query: $query,
            group: $group,
            icon: $icon,
            section: $section,
            navOrder: $navOrder,
            routeName: $routeName,
            layout: $layout,
            readOnly: $readOnly,
            deletable: $deletable,
            editable: $editable,
            showable: $showable,
        );
    }
}
