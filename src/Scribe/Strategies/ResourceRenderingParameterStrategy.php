<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Splicewire\Beam\Rendering\ResourceRendering;
use Splicewire\Beam\Scribe\OpenApi\RenderingDeliveryGenerator;

/**
 * Document a rendering route's ONE query parameter — `?format=` — off the rendering's own live
 * enumeration (api-surface-coherence ticket 32 §B).
 *
 * The sibling of {@see ParticleListParameterStrategy} one macro over, and the same argument: the route
 * already carries the declaration, so this needs no config lookup and no per-resource registration. The
 * enum is {@see ResourceRendering::formats()} read at generation time, which is the same call the
 * controller now makes at request time — the published set and the enforced set are one expression, not
 * two that agree today.
 *
 * ## Silence over a zero-member enum
 *
 * A rendering enumerating NO formats documents no `format` parameter AT ALL, rather than an enum with
 * nothing in it. That is not a rendering shortcoming to paper over: an empty `formats()` states "one
 * representation, no format axis", the controller validates nothing for it, and publishing an empty enum
 * would advertise a parameter that is read by nobody. The absent parameter IS the accurate description.
 *
 * ## The default rides `example`, not a `default` keyword
 *
 * Scribe's parameter model has no `default` slot, so the applied default is stated in the description and
 * mirrored into `example` — where it does double duty, since it is also the value a reader should send
 * first. The machine-readable `schema.default` is written at document assembly by
 * {@see RenderingDeliveryGenerator}, which is where the rest of this
 * route's un-expressible spec lives too.
 *
 * Defers (`null`) for any non-rendering route.
 */
class ResourceRenderingParameterStrategy extends Strategy
{
    use ReadsRenderingStamp;

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $stamp = $this->renderingStamp($endpointData);

        if ($stamp === null) {
            return null; // Not a rendering route — defer.
        }

        [, $rendering] = $stamp;

        $formats = $rendering->formats();

        if ($formats === []) {
            return []; // No format axis. See the class docblock: silence beats a zero-member enum.
        }

        $default = $this->delivery($rendering)['default'];

        return ['format' => [
            'type' => 'string',
            'description' => $this->description($formats, $default),
            'required' => false,
            'enumValues' => $formats,
            'example' => $default,
        ]];
    }

    /**
     * @param  list<string>  $formats
     */
    private function description(array $formats, ?string $default): string
    {
        $set = implode(', ', array_map(fn (string $format) => "`{$format}`", $formats));

        $applied = $default === null
            ? ' Omit it to get the rendering\'s own default.'
            : " Defaults to `{$default}`.";

        return "Which representation to deliver — one of {$set}.".$applied
            .' This is the set the RESOURCE can emit; the individual record narrows it to what its own '
            .'profile supports, and a format outside that narrower set comes back 422 on `format`.';
    }
}
