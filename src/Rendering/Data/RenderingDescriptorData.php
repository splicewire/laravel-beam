<?php

namespace Splicewire\Beam\Rendering\Data;

use Schemastud\DataSchemas\Attributes\ArrayItems;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Rendering\DeclaresDelivery;
use Splicewire\Beam\Rendering\Http\RenderingsController;
use Splicewire\Beam\Rendering\RenderingCertifier;
use Splicewire\Beam\Rendering\ResourceRendering;

/**
 * One rendering, as a client discovering it sees it (api-surface-coherence ticket 09 §7, build 33).
 *
 * Everything here is a DECLARATION read off the rendering itself — no record is loaded and none is
 * needed. `formats` is the set {@see RenderingsController} enforces
 * (ticket 32 §D), so what this publishes is what the wire accepts rather than what it hopes to.
 *
 * The `writable` / `fidelity` pair comes from the ROUTE TABLE, not from a live certification: the write
 * verb was granted at mount time by {@see RenderingCertifier} and frozen into
 * the route's defaults, exactly as the read routes freeze it. Reporting a fresh certification here would
 * let the catalog advertise a `POST` that no route serves.
 *
 * A rendering declining {@see DeclaresDelivery} publishes empty delivery facts with
 * `declaresDelivery: false`. That distinction is deliberate and the interface's own docblock asks for it:
 * an undeclared media type is an absence worth closing, and collapsing it into "no media types" would
 * make it read as a decision.
 */
#[TypeScript]
class RenderingDescriptorData extends Data
{
    /**
     * @param  list<string>  $formats
     * @param  list<string>  $mediaTypes
     * @param  array<string, string>  $deliveryHeaders
     */
    public function __construct(
        #[Description('The rendering\'s name — the terminal URI segment of its read route, e.g. `export`.')]
        public string $name,

        #[ArrayItems('string')]
        #[Description(
            'The formats this rendering accepts on `?format=`, most-canonical first. An EMPTY list means '
            .'the rendering has no format axis at all: one representation, no `?format=` parameter, and '
            .'nothing rejected. Read `hasFormatAxis` rather than testing the list for emptiness.'
        )]
        public array $formats,

        #[Description('False when the rendering enumerates no formats — it has one representation and ignores `?format=`.')]
        public bool $hasFormatAxis,

        #[Description('Whether the rendering states its delivery facts. When false, the three fields below are empty because nothing was declared, not because nothing is delivered.')]
        public bool $declaresDelivery,

        #[ArrayItems('string')]
        #[Description('The distinct media types this rendering can deliver, most-canonical first. Empty when `declaresDelivery` is false.')]
        public array $mediaTypes,

        #[Description('Response headers the rendering adds on every delivery, mapped to what each carries. Transport-level `Content-Type` is not listed — it is implied by `mediaTypes`.')]
        public array $deliveryHeaders,

        #[Description('The format applied when the caller names none. Null when the rendering has no format axis, or has not declared its delivery.')]
        public ?string $defaultFormat,

        #[Description('Whether a write verb is mounted — `POST {id}/{name}` exists only where reversibility was PROVEN, never where it was claimed.')]
        public bool $writable,

        #[Description('The certified fidelity behind `writable` — `lossless-eligible` or `lossy`. A rendering cannot declare this; the certifier decides it.')]
        public string $fidelity,
    ) {}

    /**
     * Project a live rendering against the verb grant frozen into the route table at mount time.
     */
    public static function fromRendering(ResourceRendering $rendering, string $fidelity, bool $writable): static
    {
        $formats = array_values($rendering->formats());
        $delivers = $rendering instanceof DeclaresDelivery;

        return new static(
            name: $rendering->name(),
            formats: $formats,
            hasFormatAxis: $formats !== [],
            declaresDelivery: $delivers,
            mediaTypes: $delivers ? array_values($rendering->mediaTypes()) : [],
            deliveryHeaders: $delivers ? $rendering->deliveryHeaders() : [],
            defaultFormat: $delivers ? $rendering->defaultFormat() : null,
            writable: $writable,
            fidelity: $fidelity,
        );
    }
}
