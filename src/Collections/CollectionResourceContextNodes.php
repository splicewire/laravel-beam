<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Http\Request;
use Schemastud\Frame\Contracts\ResourceContextContributor;
use Splicewire\Beam\Particle\Backing\ResourceAddress;

/**
 * Adds the selected Collection schema's field contexts to the records Frame manifest.
 *
 * frame-foundation 93: a context contributor registered under the key `beam.collections` in frame's
 * `frame.context-contributors` registry, after `beam.contributions`. It answers only its own `fields.*` nodes; the merge
 * it once did over the contribution nodes it wrapped is the registry's fold now (a later position wins a shared context).
 * Request-scoped: beam binds it with `scoped()` and its registered factory resolves that binding per call.
 */
final class CollectionResourceContextNodes implements ResourceContextContributor
{
    public function __construct(
        private Request $request,
        private CollectionSchemaResolver $collections,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function nodesFor(string $key): array
    {
        $nodes = [];
        if ($key !== 'records') {
            return $nodes;
        }

        $address = ResourceAddress::resolve(
            new CollectionBacking($this->request, $this->collections),
            $this->request,
            required: false,
        );
        if (! $address instanceof CollectionAddressData) {
            return $nodes;
        }

        $order = 0;
        foreach ($this->collections->resolve($address->collection)->properties() as $path => $property) {
            $fieldOrder = $order++;
            $contexts = $property['x-stud-widget-contexts'] ?? [];
            if (! is_array($contexts)) {
                continue;
            }

            // A JSON Schema field participates in the default list column unless its owning
            // schema explicitly opts out or supplies a narrower context declaration. The schema
            // remains authoritative for widget, label, sort, and every other context option.
            if (! array_key_exists('list-column', $contexts)) {
                $contexts['list-column'] = ['participates' => true];
            }

            $listColumn = $contexts['list-column'];
            if (is_array($listColumn) && ($listColumn['participates'] ?? true) !== false) {
                if (! array_key_exists('sort', $listColumn)) {
                    $listColumn['sort'] = $fieldOrder;
                }
                if (! array_key_exists('label', $listColumn) && is_string($property['title'] ?? null)) {
                    $listColumn['label'] = $property['title'];
                }
                $contexts['list-column'] = $listColumn;
            }

            $pointer = 'fields.'.$path;
            $nodes[$pointer] = array_merge($nodes[$pointer] ?? [], $contexts);
        }

        return $nodes;
    }
}
