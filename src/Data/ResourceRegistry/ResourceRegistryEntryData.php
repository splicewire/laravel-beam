<?php

namespace Splicewire\Beam\Data\ResourceRegistry;

use Schemastud\Frame\Attributes\Column;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Authorization\ModelLessReadPosture;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Doctor\ModelLessReadGateAudit;
use Splicewire\Beam\Particle\Registry\ResourceRegistryBacking;
use Splicewire\Beam\Particle\ResourceRegistryRow;

/**
 * One registered particle resource as the operator Resources area serves it — the READ projection of the
 * `resources` resource that {@see ResourceRegistryBacking} streams.
 *
 * ## A row exists only for a resource the viewer may LIST
 *
 * The backing drops every resource {@see ResourceVisibility::listable()} refuses before this object is
 * built, so nothing here is redacted: a key, a backing class and a capability set disclose a resource's
 * schema surface, and the whole row is absent rather than half-present (registry-kernel ticket 17 D5 —
 * a gated resource is absent, and the page says so).
 *
 * ## Capability, intent and affordance are three objects on purpose
 *
 * {@see $capabilities} is what the backing CAN do, {@see $intent} what the declaration MAY do, and
 * {@see $affordances} the intersection a surface acts on. Merging them into one set would make the one
 * reading worth having — intent exceeding capability, {@see $disagreements} — invisible in the product.
 *
 * ## The two host-shaped fields
 *
 * {@see $surfaces} and {@see $navSeated} are filled by the host's locator; everything else is read from
 * the registry. See {@see ResourceSurfaceData}.
 */
#[TypeScript]
class ResourceRegistryEntryData extends BeamData
{
    /**
     * @param  list<string>  $realms  membership, explicit rung first
     * @param  list<string>  $readGateFindings  the model-less read-gate audit's per-resource reading
     *                                          ({@see ModelLessReadGateAudit}); empty ⇒ nothing to report
     * @param  list<string>  $disagreements  intent exceeding capability, one phrase per finding
     * @param  list<ResourceSurfaceData>  $surfaces  one per realm membership, in membership order
     */
    public function __construct(
        /** The resource key — also the record id, since a key is unique in the registry. */
        public string $id,
        #[Column(label: 'Key', sort: 1)]
        public string $key,
        #[Column(label: 'Label', sort: 0)]
        public string $label,
        #[Column(label: 'Section', sort: 2)]
        public ?string $section,
        public array $realms,
        public bool $framed,
        /** The model FQCN, or null when the backing backs no single model. */
        public ?string $model,
        /** The model's short class name, or null. */
        public ?string $modelName,
        /** The backing FQCN (a model class-string reads as itself). */
        public string $backing,
        /** The backing's short class name. */
        public string $backingName,
        /** The Frame handler this host resolves for the key, or null when none is bound. */
        public ?string $handler,
        public ResourceCapabilitiesData $capabilities,
        public ResourceIntentData $intent,
        public ResourceAffordancesData $affordances,
        /**
         * How reads of a model-less resource are gated: `declared-ability` or `undeclared`
         * ({@see ModelLessReadPosture}); `model-backed` when the resource has a model for a policy.
         */
        #[Column(label: 'Read posture', sort: 3)]
        public string $posture,
        public array $readGateFindings,
        public array $disagreements,
        public array $surfaces,
        /** Some realm's navigation seats this resource — false is the default, not a defect. */
        public bool $navSeated,
    ) {}

    /**
     * Project one visible report row. The posture and findings are handed in rather than recomputed so the
     * backing asks {@see ResourceVisibility} once per resource.
     *
     * @param  list<string>  $readGateFindings
     * @param  list<ResourceSurfaceData>  $surfaces
     */
    public static function fromRow(ResourceRegistryRow $row, string $posture, array $readGateFindings, array $surfaces): self
    {
        $affordances = $row->affordances();

        return new self(
            id: $row->key,
            key: $row->key,
            label: $row->label,
            section: $row->section,
            realms: $row->realms,
            framed: $row->framed,
            model: $row->model,
            modelName: $row->model === null ? null : self::shortName($row->model),
            backing: $row->backing,
            backingName: self::shortName($row->backing),
            handler: $row->handler,
            capabilities: new ResourceCapabilitiesData(
                streams: $row->streams,
                queries: $row->queries,
                resolves: $row->resolves,
                writes: $row->writes,
                vocabulary: $row->vocabulary,
            ),
            intent: new ResourceIntentData(
                readOnly: $row->readOnly,
                creatable: $row->creatable,
                editable: $row->editable,
                deletable: $row->deletable,
                showable: $row->showable,
                policy: $row->policy,
            ),
            affordances: new ResourceAffordancesData(...$affordances),
            posture: $posture,
            readGateFindings: $readGateFindings,
            disagreements: $row->disagreements,
            surfaces: $surfaces,
            navSeated: array_filter($surfaces, fn (ResourceSurfaceData $surface): bool => $surface->navSeat) !== [],
        );
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
