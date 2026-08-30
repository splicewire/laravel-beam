<?php

namespace Splicewire\Beam\Scribe\OpenApi;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Splicewire\Beam\Scribe\Strategies\ParticleOperationDeliveryStrategy;

/**
 * Write a DELIVERED route's real response contract into the document (api-surface-coherence ticket 32 §C).
 *
 * The document-assembly half of {@see ParticleOperationDeliveryStrategy}. It was written to serve TWO
 * declaration surfaces — the operation's `delivery:` slot and the rendering registry's
 * `ResourceRenderingResponseStrategy` — because what it writes is a function of the DELIVERY CONTRACT
 * and not of which registry produced it; particle-operation-surface 13 dissolved the rendering half, so
 * one surface remains and the property it was built on is what made that deletion a non-event. It
 * exists for the same reason `DataSchemaGenerator` does: two things a delivering endpoint genuinely IS
 * cannot be said in Scribe's per-endpoint model.
 *
 *  - **A 200 in several media types.** Scribe keys responses by status and drops a second response for a
 *    status it already holds when the content type differs. A composition's export answers in six.
 *  - **A parameter default.** Scribe's `Parameter` has no `default` slot at all; the strategy mirrors it
 *    into `example`, and the machine-readable `schema.default` is written here.
 *
 * ## What it writes, and what it deliberately does not
 *
 * The 200 gets one `content` entry per declared media type, each an untyped body (`type: string`) except
 * where the media type is JSON, plus the delivery's own headers. It does NOT attempt a schema for the
 * bodies: `text/calendar` and `application/pdf` have no JSON Schema, and the JSON shapes here are
 * hand-rolled by the exporters rather than projected from a Data class (`structured` is built with
 * `json_encode`, and its only typed mirror is consumer-side). Claiming a schema we do not have would be
 * this map's own disease.
 *
 * The 422 is written for every delivery that has a format axis, and it is the response that had no slot
 * at all before ticket 32 — the one a client hits by asking for a format the record cannot produce. Its
 * envelope is the host's `ValidationException` projection (`success:false`, `message`, `errors.format`),
 * which is framework-wide rather than per-delivery, so it is built here rather than stashed.
 *
 * A delivery with NO format axis gets no 422 from this hook: there is nothing it rejects.
 *
 * ⚠️ **It was called `RenderingDeliveryGenerator` until particle-operation-surface 13.** The rename was
 * deferred to this ticket on purpose — it edits a class-string in `~/Herd/splicewire-app/config/scribe.php`,
 * the only host in the estate that wires it, and 13 is the change that deletes the rendering half. A
 * reader chasing the old name in a ticket or a docblock is reading pre-13 prose.
 *
 * ⚠️ **The 200 it writes REPLACES whatever the response strategies put there.** An operation declaring
 * both `output:` and `delivery:` publishes the delivery's media types, not the Data class's JSON
 * envelope — which is correct (the delivery is the wire fact and `output:` is a payload shape), and is
 * why the generator runs at assembly, after every strategy. An operation declaring no `delivery:`
 * stashes nothing and is never touched here.
 */
class DeliveryGenerator extends OpenApiGenerator
{
    /**
     * The endpoint `custom` key every delivering strategy stashes on, and the one this hook reads.
     *
     * Homed on the GENERATOR rather than on a strategy since particle-operation-surface 14, when two
     * strategies wrote it and 13 was scheduled to delete one of them. 13 has now landed: the alias
     * `ResourceRenderingResponseStrategy::STASH` went with that class, and the key stayed exactly where
     * 14 put it — which is the whole point of having moved it a day early.
     */
    public const STASH = 'renderingDelivery';

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $delivery = $endpoint->custom[self::STASH] ?? null;

        if (! is_array($delivery)) {
            return $pathItem;
        }

        // Scribe serialises an EMPTY response map as a stdClass so it renders as `{}` rather than `[]`,
        // and this strategy deliberately returns no flat responses — so the empty case is the normal one
        // here, not an edge.
        $responses = $pathItem['responses'] ?? [];
        $pathItem['responses'] = is_array($responses) ? $responses : (array) $responses;

        $pathItem['responses']['200'] = array_filter([
            'description' => $this->deliveredDescription($delivery),
            'content' => $this->content($delivery['mediaTypes']),
            'headers' => $this->headers($delivery['headers']),
        ]);

        if (($delivery['formats'] ?? []) !== []) {
            $pathItem['responses']['422'] = $this->rejection();
        }

        return $this->applyDefault($pathItem, $delivery['default'] ?? null);
    }

    /**
     * @param  array{mediaTypes: list<string>, formats: list<string>}  $delivery
     */
    private function deliveredDescription(array $delivery): string
    {
        return $delivery['formats'] === []
            ? 'The rendered document.'
            : 'The rendered document, in the requested format. The media type follows the format — the '
                .'entry matching your `format` is the one you get.';
    }

    /**
     * @param  list<string>  $mediaTypes
     * @return array<string, array<string, mixed>>
     */
    private function content(array $mediaTypes): array
    {
        $content = [];

        foreach ($mediaTypes as $mediaType) {
            // A JSON body is an object whose shape is not projected from anything (see the class
            // docblock); everything else is a document body, which OpenAPI models as a string.
            $content[$mediaType] = ['schema' => str_contains($mediaType, 'json')
                ? ['type' => 'object']
                : ['type' => 'string']];
        }

        return $content;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, array<string, mixed>>
     */
    private function headers(array $headers): array
    {
        $spec = [];

        foreach ($headers as $name => $description) {
            $spec[$name] = [
                'description' => $description,
                'schema' => ['type' => 'string'],
            ];
        }

        return $spec;
    }

    /**
     * The format-rejection response: the host's `ValidationException` envelope, keyed on `format`.
     *
     * @return array<string, mixed>
     */
    private function rejection(): array
    {
        return [
            'description' => 'The requested format is not one this record can be rendered as. The route '
                .'accepts every format the resource declares; the record narrows that set.',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'const' => false],
                    'message' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'properties' => ['format' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ],
                ],
            ]]],
        ];
    }

    /**
     * Put the applied default onto the `format` parameter's schema. Scribe emits the parameter (the
     * strategy declared it) but has no field that becomes `schema.default`.
     *
     * @param  array<string, mixed>  $pathItem
     * @return array<string, mixed>
     */
    private function applyDefault(array $pathItem, ?string $default): array
    {
        if ($default === null) {
            return $pathItem;
        }

        foreach ($pathItem['parameters'] ?? [] as $index => $parameter) {
            if (($parameter['name'] ?? null) === 'format') {
                $pathItem['parameters'][$index]['schema']['default'] = $default;
            }
        }

        return $pathItem;
    }
}
