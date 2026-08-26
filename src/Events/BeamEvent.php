<?php

namespace Splicewire\Beam\Events;

use Attribute;
use ReflectionClass;

/**
 * An event class declaring itself publishable: `#[BeamEvent('tenants.provisioned', subject: Tenant::class)]`.
 *
 * ## A FEEDER, not a second seam
 *
 * This ships in v1 explicitly as sugar over {@see EventTypeRegistry::register()} (api-surface-coherence
 * ticket 14 §1). The scanner reads the attribute and calls `register()`; the registry remains the only
 * thing holding state, and every one of its registration-time validations applies identically to an
 * annotated class and a hand-registered entry. There is no attribute-only path, no lazy resolution off
 * the attribute at read time, and no second store.
 *
 * That is a guard against a drift this estate has already paid for once: `ParticleResourceRegistry`'s
 * index entry claimed `AttributeScan` while all seventeen of the flagship host's keys registered
 * imperatively with `frame.discover_paths` commented out. A feeder cannot drift from the accumulator,
 * because there is nothing for it to drift from — it is a call site.
 *
 * ## Where it is scanned
 *
 * Nowhere, by default. `beam.core.events.discover_paths` is empty out of the box and
 * `BeamServiceProvider` skips the scan entirely when it is; a host opts in by listing paths, and
 * {@see BeamEventRegistrar} does the reading. The catalog's shipping entries are registered
 * imperatively from the packages that own them, which is the honest shape while three entries exist.
 *
 * The one thing the attribute buys today is that the declaration sits ON the event class, next to the
 * payload it dispatches — which is exactly where the payload Data class will be declared when
 * api-surface-coherence tickets 27/31 author them.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class BeamEvent
{
    /**
     * @param  string  $name  `{resourceKey}.{verbPhrase}`, plural-verbatim
     * @param  class-string|null  $subject  the model a delivery is about
     * @param  class-string|null  $payload  the Data class describing the wire body, when one exists
     * @param  bool  $subjectless  this event genuinely has no subject, and says so
     * @param  string  $description  one line for `GET /hooks/events`
     */
    public function __construct(
        public string $name,
        public ?string $subject = null,
        public ?string $payload = null,
        public bool $subjectless = false,
        public string $description = '',
    ) {}

    public function toEventType(): EventType
    {
        return new EventType(
            name: $this->name,
            subject: $this->subject,
            payload: $this->payload,
            subjectless: $this->subjectless,
            description: $this->description,
        );
    }

    /**
     * Every `#[BeamEvent]` on `$class`, in declaration order. Repeatable, because one event class may
     * legitimately publish under several names — the `{status}` producers are the live shape, and
     * expanding them at declaration keeps the catalog flat and literal.
     *
     * @return list<self>
     */
    public static function on(string $class): array
    {
        if (! class_exists($class)) {
            return [];
        }

        return array_values(array_map(
            fn ($attribute) => $attribute->newInstance(),
            (new ReflectionClass($class))->getAttributes(self::class),
        ));
    }
}
