<?php

declare(strict_types=1);

namespace Splicewire\Beam\Particle;

use Closure;
use RuntimeException;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;

/**
 * An admin-editable particle resource: a {@see ParticleResource} (the REST/runtime core — model, data,
 * includes, hooks) **plus** the editor/manifest concerns (nav, layout, edit shape, policy, form mode) and
 * a projection into Frame's agnostic contract ({@see ResourceDefinition}).
 *
 * This is the ADR-0156 merge: the runtime half of the retired `#[AdminResource]` attribute fuses with
 * `ParticleResource`, and the whole declaration lives in **beam** (which now depends DOWN on frame, so it
 * may reference `Schemastud\Frame\*` directly). The honest subtype relation holds — every admin resource
 * IS a particle resource + a manifest; not every particle resource is an admin resource. A REST-only
 * surface declares {@see ParticleResource}; an editable one declares this and lights up BOTH transports
 * (the `@schemastud/frame` editor via {@see ResourceDefinition} + `ParticleController` REST) off one
 * object, over the one `ParticleWriter`/`ParticleHydrator` runtime.
 *
 * Scope: **model-backed** resources only. A union/aggregate surface (e.g. a review queue) is not a
 * particle resource — it has no single model and no write path — and stays a Frame `UnionSource` (the gap
 * policy: widen a Frame port, don't subclass this).
 */
class ParticleAdminResource extends ParticleResource
{
    /**
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
     * @param  string|null  $layout  inner-layout grammar ('single'|'subnav'|'master-detail'); emitted on the ContextManifest
     * @param  bool  $readOnly  a machine-authored / view-only resource — no create/edit/delete through Frame (ADR-0156 §83). Projects `creatable: !$readOnly`; the generic {@see ParticleFrameResourceHandler} refuses store/update/destroy with a 405 when `! creatable`. Default false — writable.
     *
     * The remaining params are {@see ParticleResource}'s runtime core.
     */
    public function __construct(
        string $key,
        string $model,
        ?string $data = null,
        public readonly string $label = '',
        ?string $input = null,
        array $includes = [],
        bool $filterable = true,
        int $perPage = 20,
        ?Closure $prepare = null,
        ?Closure $afterWrite = null,
        ?Closure $project = null,
        public readonly string $form = 'bare',
        public readonly ?string $editData = null,
        public readonly ?string $policy = null,
        public readonly ?string $query = null,
        public readonly ?string $group = null,
        public readonly ?string $icon = null,
        public readonly ?string $section = null,
        public readonly ?int $navOrder = null,
        public readonly ?string $routeName = null,
        public readonly ?string $layout = null,
        public readonly bool $readOnly = false,
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
        );
    }

    /**
     * Project into Frame's agnostic manifest contract. Beam reflects this declaration and *feeds* Frame's
     * manifest machinery (Frame renders what it is handed; it never names a model). Model-backed ⇒
     * `sourceKind: 'model'`, creatable unless declared `readOnly` (ADR-0156 §83).
     */
    public function toResourceDefinition(): ResourceDefinition
    {
        if ($this->data === null) {
            throw new RuntimeException(
                "ParticleAdminResource [{$this->key}] has no read Data class; an admin resource must declare one (the SchemaDataResolver fallback was removed by ADR-0156)."
            );
        }

        return new ResourceDefinition(
            key: $this->key,
            sourceKind: 'model',
            model: $this->model,
            source: null,
            data: $this->data,
            creatable: ! $this->readOnly,
            query: $this->query,
            editData: $this->editData,
            policy: $this->policy,
            form: $this->form,
            nav: new NavMetadata(
                label: $this->label,
                group: $this->group,
                icon: $this->icon,
                section: $this->section,
                navOrder: $this->navOrder,
                routeName: $this->routeName,
            ),
            layout: $this->layout,
        );
    }
}
