<?php

namespace Splicewire\Beam\Scribe\OpenApi;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Splicewire\Beam\Scribe\Strategies\ResourceRenderingResponseStrategy;

/**
 * Write a rendering route's real response contract into the document (api-surface-coherence ticket 32 §C).
 *
 * The document-assembly half of {@see ResourceRenderingResponseStrategy}, and it exists for the same
 * reason `DataSchemaGenerator` does: two things a rendering endpoint genuinely IS cannot be said in
 * Scribe's per-endpoint model.
 *
 *  - **A 200 in several media types.** Scribe keys responses by status and drops a second response for a
 *    status it already holds when the content type differs. A composition's export answers in six.
 *  - **A parameter default.** Scribe's `Parameter` has no `default` slot at all; the strategy mirrors it
 *    into `example`, and the machine-readable `schema.default` is written here.
 *
 * ## What it writes, and what it deliberately does not
 *
 * The 200 gets one `content` entry per declared media type, each an untyped body (`type: string`) except
 * where the media type is JSON, plus the rendering's own headers. It does NOT attempt a schema for the
 * bodies: `text/calendar` and `application/pdf` have no JSON Schema, and the JSON shapes here are
 * hand-rolled by the exporters rather than projected from a Data class (`structured` is built with
 * `json_encode`, and its only typed mirror is consumer-side). Claiming a schema we do not have would be
 * this map's own disease.
 *
 * The 422 is written for every rendering that has a format axis, and it is the response that had no slot
 * at all before this ticket — the one a client hits by asking for a format the record cannot produce. Its
 * envelope is the host's `ValidationException` projection (`success:false`, `message`, `errors.format`),
 * which is framework-wide rather than per-rendering, so it is built here rather than stashed.
 *
 * A rendering with NO format axis gets no 422 from this hook: there is nothing it rejects.
 */
class RenderingDeliveryGenerator extends OpenApiGenerator
{
    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $delivery = $endpoint->custom[ResourceRenderingResponseStrategy::STASH] ?? null;

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
