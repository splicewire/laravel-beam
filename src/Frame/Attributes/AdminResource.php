<?php

declare(strict_types=1);

namespace Splicewire\Beam\Frame\Attributes;

use Attribute;
use InvalidArgumentException;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Frame\AdminResourceRegistry;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;

/**
 * Marks a Data class as an editable admin resource — beam's single source of truth
 * for the frame manifest (derive, don't hand-author twice). Placed on the ONE
 * read+edit Data class per resource (the single-class zero-drift default); the
 * annotated class itself becomes the resource's `data` (read/index projection)
 * and, absent an `editData` escape hatch, its edit shape too.
 *
 * This attribute is beam's, NOT the agnostic frame foundation's (ADR-0156: "frame has
 * no concept of admin"). `#[AdminResource]` names an opinion — that a resource is
 * editable, model-backed, policy-gated, navigable — and binds `model`/`policy`; that
 * opinion belongs to the paid CMS engine. Beam's {@see AdminResourceRegistry}
 * reflects it into frame's agnostic {@see ResourceDefinition},
 * which frame's manifest machinery serves. Backing is EITHER a single Eloquent `model`
 * OR a custom `source` (class-string<UnionSource>) that fuses many underlying
 * models/services into one list+detail resource — exactly one of the two must be set
 * (both/neither throws at registration). `query` optionally names a data-filters query
 * class so the ListShell facets bar can ride an existing filter schema; it does not
 * apply to a `source`-backed (union) resource.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class AdminResource
{
    /**
     * The three sanctioned inner-layout grammars (component-seams ticket 02) a
     * resource surface may declare, in the FrameLayout socket's `variant` tokens
     * (component-seams ticket 09): `single` (SingleColumn), `subnav` (SubNavColumn),
     * `master-detail` (MasterDetail). Emitted verbatim on the resource's
     * ContextManifest so a host resolves `variant={manifest.layout}` (ticket 31).
     */
    public const Layouts = ['single', 'subnav', 'master-detail'];

    /**
     * @param  string  $key  resource slug ('axis-leaf', 'layers', …)
     * @param  string  $label  nav label
     * @param  class-string|null  $model  the Eloquent model (null for a source-backed union resource)
     * @param  class-string|null  $source  a UnionSource fusing many sub-sources; mutually exclusive with $model
     * @param  string|null  $group  nav group heading
     * @param  string|null  $icon  nav icon key
     * @param  'enriched'|'bare'  $form  per-resource default form mode
     * @param  class-string|null  $editData  rare escape-hatch edit DTO (input-shape divergence)
     * @param  string|null  $policy  ability/policy key the injected can() resolves against
     * @param  class-string|null  $query  data-filters query class (optional filter schema)
     * @param  string|null  $section  the host sitemap section this resource auto-attaches into (nav wiring); null = not in the primary nav
     * @param  int|null  $navOrder  placement within the section (lower first; null sorts after ordered siblings)
     * @param  string|null  $routeName  stable route identity a host binds the generated leaf under; null = the host derives one from `key`
     * @param  'single'|'subnav'|'master-detail'|null  $layout  the sanctioned inner-layout grammar this resource's surface uses (ticket 02); emitted on the ContextManifest so a host resolves the FrameLayout `variant` from the manifest. null = unspecified → the socket's `SingleColumn` fallback (ticket 09).
     * @param  bool  $readOnly  a machine-authored / view-only resource — no create/edit/delete through Frame (ADR-0156 §83 read-only widening). Projects `creatable: !$readOnly` into the {@see ResourceDefinition}; the generic {@see ParticleFrameResourceHandler} refuses store/update/destroy with a 405 when `! creatable`. Default false — every existing resource stays writable.
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $model = null,
        public ?string $source = null,
        public ?string $group = null,
        public ?string $icon = null,
        public string $form = 'bare',
        public ?string $editData = null,
        public ?string $policy = null,
        public ?string $query = null,
        public ?string $section = null,
        public ?int $navOrder = null,
        public ?string $routeName = null,
        public ?string $layout = null,
        public bool $readOnly = false,
    ) {
        if ($this->layout !== null && ! in_array($this->layout, self::Layouts, true)) {
            throw new InvalidArgumentException(
                "Unknown resource layout [{$this->layout}]; expected one of: ".implode(', ', self::Layouts).', or null.'
            );
        }
    }
}
