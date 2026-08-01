<?php

declare(strict_types=1);

namespace Splicewire\Beam\Frame;

use InvalidArgumentException;
use ReflectionClass;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Frame\Attributes\AdminResource;

/**
 * Beam's resource DECLARATION registry — the producer behind frame's agnostic
 * {@see ResourceRegistry} port. It owns the `#[AdminResource]` opinion frame refuses to
 * carry (ADR-0156): the boot scan reflects annotated Data classes into frame's generic
 * {@see ResourceDefinition}, and frame's manifest machinery serves what it is handed.
 *
 * Discovery is the {@see AdminResource} attribute (single source of truth): reflect the
 * configured resource classes + any classes under the configured discover paths, plus a
 * {@see self::register()} escape hatch for attribute-less resources. Beam binds this onto
 * frame's `ResourceRegistry::class` port so frame never imports a beam type — the arrow
 * points DOWN (beam → frame), never up.
 */
class AdminResourceRegistry implements ResourceRegistry
{
    /** @var array<string, ResourceDefinition> */
    private array $resources = [];

    /**
     * Reflect an #[AdminResource]-annotated Data class and register its definition.
     *
     * @param  class-string  $dataClass
     */
    public function registerClass(string $dataClass): void
    {
        if (! class_exists($dataClass)) {
            throw new InvalidArgumentException("Admin resource class [{$dataClass}] does not exist.");
        }

        $attribute = $this->readAttribute(new ReflectionClass($dataClass));

        if ($attribute === null) {
            throw new InvalidArgumentException(
                "Class [{$dataClass}] is not annotated with #[AdminResource]; use register() for attribute-less resources."
            );
        }

        $hasModel = $attribute->model !== null;
        $hasSource = $attribute->source !== null;

        if ($hasModel === $hasSource) {
            throw new InvalidArgumentException(
                "Admin resource [{$attribute->key}] must set exactly one of `model` or `source`; "
                .($hasModel ? 'both were set.' : 'neither was set.')
            );
        }

        $this->register(self::toDefinition($dataClass, $attribute));
    }

    /**
     * The ->register() escape hatch: add an attribute-less resource imperatively.
     */
    public function register(ResourceDefinition $definition): void
    {
        $this->resources[$definition->key] = $definition;
    }

    /**
     * Scan configured class-strings and discover-paths for #[AdminResource] classes.
     * Idempotent — re-scanning overwrites by key, never duplicates.
     *
     * @param  array<int, class-string>  $classes  explicit resource class list
     * @param  array<int, string>  $paths  filesystem paths to scan for annotated classes
     */
    public function discover(array $classes = [], array $paths = []): void
    {
        foreach ($classes as $class) {
            $this->registerClass($class);
        }

        foreach ($this->scanPaths($paths) as $class) {
            $this->registerClass($class);
        }
    }

    public function has(string $key): bool
    {
        return isset($this->resources[$key]);
    }

    public function get(string $key): ResourceDefinition
    {
        return $this->resources[$key] ?? throw new InvalidArgumentException(
            "No frame resource registered for key [{$key}]."
        );
    }

    /**
     * The served manifest: flat sibling entries, insertion order.
     *
     * @return list<ResourceDefinition>
     */
    public function all(): array
    {
        return array_values($this->resources);
    }

    /**
     * Project an #[AdminResource]-annotated Data class into frame's agnostic
     * {@see ResourceDefinition}. The annotated class itself becomes `data` (the
     * single-class read+edit default). A `source` yields a `service` union resource
     * (model nulled, editData/query forced null, not creatable); otherwise a
     * `model`-backed resource is creatable.
     *
     * @param  class-string  $dataClass
     */
    public static function toDefinition(string $dataClass, AdminResource $attribute): ResourceDefinition
    {
        $nav = new NavMetadata(
            label: $attribute->label,
            group: $attribute->group,
            icon: $attribute->icon,
            section: $attribute->section,
            navOrder: $attribute->navOrder,
            routeName: $attribute->routeName,
        );

        if ($attribute->source !== null) {
            return new ResourceDefinition(
                key: $attribute->key,
                sourceKind: 'service',
                model: null,
                source: $attribute->source,
                data: $dataClass,
                creatable: false,
                query: null,
                // A service-backed resource is not writable THROUGH Frame (store/update/destroy 405), but
                // it may still carry an `editData` create-SCHEMA escape hatch (ADR-0156 §83): the schema
                // endpoint emits `editData ?? data`, and a host may render that create form while SUBMITTING
                // it to a survivor REST endpoint (e.g. tenant provisioning). Purely a schema surface — it
                // grants no Frame write path (creatable/editable/deletable stay false). Default null ⇒ every
                // existing source-backed resource (review-queue, members) is unchanged.
                editData: $attribute->editData,
                policy: $attribute->policy,
                form: $attribute->form,
                nav: $nav,
                layout: $attribute->layout,
                deletable: false,
                editable: false,
            );
        }

        return new ResourceDefinition(
            key: $attribute->key,
            sourceKind: 'model',
            model: $attribute->model,
            source: null,
            data: $dataClass,
            creatable: ! $attribute->readOnly,
            query: $attribute->query,
            editData: $attribute->editData,
            policy: $attribute->policy,
            form: $attribute->form,
            nav: $nav,
            layout: $attribute->layout,
            // Delete gate is independent of create (ADR-0156 §83): null follows the create gate
            // (every existing resource unchanged), an explicit true opens destroy on a not-creatable
            // (readOnly) resource — the fragments "list + delete, no create/edit" shape.
            deletable: $attribute->deletable ?? ! $attribute->readOnly,
            // Edit (show/update) gate is independent of create (ADR-0156 §83): null follows the create
            // gate (every existing resource unchanged), an explicit false closes in-place edit on an
            // otherwise creatable resource — the invitations "create + delete, no edit" shape.
            editable: $attribute->editable ?? ! $attribute->readOnly,
        );
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function readAttribute(ReflectionClass $class): ?AdminResource
    {
        $attrs = $class->getAttributes(AdminResource::class);

        return empty($attrs) ? null : $attrs[0]->newInstance();
    }

    /**
     * Find #[AdminResource]-annotated class-strings under the given paths — the live filesystem
     * walk. Delegates the generic file→FQCN→attribute machinery to popcorn's
     * {@see AttributedClassScanner} (extracted from the copy that used to live here). Only classes
     * carrying the attribute are returned (others ignored, not errors), so a discover path may point
     * at a whole Data directory. `instanceof: false` preserves the original exact-match behaviour
     * (the base #[AdminResource] has no preset subclasses).
     *
     * @param  array<int, string>  $paths
     * @return list<class-string>
     */
    public function scanPaths(array $paths): array
    {
        return (new AttributedClassScanner)->scan($paths, AdminResource::class, instanceof: false);
    }
}
