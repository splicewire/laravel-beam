<?php

namespace Splicewire\Beam\Localization;

use Illuminate\Support\Str;
use Schemastud\Frame\Registry\ResourceActionDefinition;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;

/**
 * A framed resource's display text, resolved where beam emits it (localization 08; ruling 01, ADR-0244). Every field
 * goes through the one resolver, {@see DeclaredText::resolve()}, with its derived key and its English default:
 *
 * - the label: `<ns>::resources.<key>.label`;
 * - the singular: `<ns>::resources.<key>.singular`;
 * - each action's label: `<ns>::actions.<resource>.<op>.label`;
 * - a group's display name: `<ns>::groups.<group>.name` (beam emits none itself; nav projection, ticket 11, calls it).
 *
 * **Owners** (ADR-0244): a resource or op whose registrant is `class:<FQCN>` (an attributed declaration) is that
 * class's package's; an imperatively registered resource is its data class's package's; an imperatively registered op
 * is its registering package's. With no owner a field renders its literal.
 *
 * **English derivations are defaults only.** A label-less resource's headlined key and an inflected singular are the
 * English default, computed from English, and never applied to translated text. A field whose declaration is empty is
 * written only when a line (or the pseudo-locale) changed it, so the English wire is byte-identical.
 *
 * A realm's configured label (`beam.core.realm_resource_overrides`) is that realm's English rung (ruling 05), answered
 * by the ladder, so a host's existing override renders as it did in English and yields to a translation.
 */
final class FrameText
{
    public function __construct(
        private DeclaredText $text,
        private TextNamespaces $namespaces,
    ) {}

    /** The text prefix for a registrant (`class:<FQCN>` or a composer name), else for `$fallbackClass`; null: none. */
    public function prefixFor(?string $registrant, ?string $fallbackClass = null, bool $packageRegistrant = false): ?string
    {
        if ($registrant !== null && str_starts_with($registrant, 'class:')) {
            return $this->namespaces->ownerOfClass(substr($registrant, 6))->keyPrefix();
        }
        if ($packageRegistrant && $registrant !== null && preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $registrant) === 1) {
            return $this->namespaces->ownerOfPackage($registrant)->keyPrefix();
        }

        return $fallbackClass === null || $fallbackClass === '' ? null : $this->namespaces->ownerOfClass($fallbackClass)->keyPrefix();
    }

    /**
     * The definition with its label, singular and action labels resolved for `$realm` (null: the request's).
     */
    public function localize(
        ResourceDefinition $definition,
        ParticleResource $resource,
        ?string $registrant,
        ?ParticleOperationRegistry $operations,
        ?string $realm,
    ): ResourceDefinition {
        $prefix = $this->prefixFor($registrant, is_string($resource->data) ? $resource->data : null);
        $key = $definition->key;

        $englishLabel = $definition->resolvedLabel();
        $englishSingular = $definition->resolvedSingularLabel();
        $label = (string) $this->text->resolve(DeclaredText::derive($prefix, 'resources', [$key], 'label'), $englishLabel, $realm);
        $singular = (string) $this->text->resolve(DeclaredText::derive($prefix, 'resources', [$key], 'singular'), $englishSingular, $realm);

        $actions = array_map(function (ResourceActionDefinition $action) use ($key, $operations, $realm): ResourceActionDefinition {
            $owner = $this->prefixFor($operations?->registrantOf($key.'.'.$action->key), packageRegistrant: true);
            $text = (string) $this->text->resolve(DeclaredText::derive($owner, 'actions', [$key, $action->key], 'label'), $action->label, $realm);

            return $text === $action->label ? $action : new ResourceActionDefinition(
                key: $action->key, label: $text, scope: $action->scope, method: $action->method, url: $action->url,
                input: $action->input, result: $action->result, destructive: $action->destructive,
            );
        }, $definition->actions);

        return $definition->withOverrides(
            // An empty declaration stays empty (frame derives the same English) unless the text changed.
            label: $definition->nav->label !== '' || $label !== $englishLabel ? $label : null,
            singularLabel: $definition->singularLabel !== '' || $singular !== $englishSingular ? $singular : null,
            actions: $actions,
        );
    }

    /**
     * A nav group's display name: `groups.<group>.name` in the registering namespace, defaulting to the headlined group
     * key (English, a default only). The group itself stays identity everywhere.
     */
    public function groupName(string $group, ?string $registrant, ?string $realm = null): string
    {
        $key = DeclaredText::derive($this->prefixFor($registrant, packageRegistrant: true) ?? '', 'groups', [$group], 'name');

        return (string) $this->text->resolve($key, Str::headline($group), $realm);
    }
}
