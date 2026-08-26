<?php

namespace Splicewire\Beam\Webhooks\Data;

use Illuminate\Database\Eloquent\Relations\Relation;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Events\EventType;

/**
 * One catalog entry, projected for `GET /hooks/events` (api-surface-coherence ticket 38, decided by
 * 12 §3).
 *
 * `resource` and `verbPhrase` are PROJECTED, not stored: the catalog holds one string and
 * {@see EventType::resourceKey()} splits it segment-wise. Publishing the split saves every client
 * re-implementing the "resource key is segment one, verb phrase is everything after" rule that
 * ticket 14 §3 fixed — and a client that re-implemented it with `explode('.', $n)[0]` would be right
 * by accident until the first multi-segment verb phrase.
 *
 * `subject` ships the model's MORPH class, never the FQCN. A wire shape that leaked
 * `Splicewire\Beam\Models\Hook` would publish beam's internal namespace to every subscriber and
 * become un-renameable; the morph alias is the name the estate already treats as public
 * (`subject_type` on the hook record is the same string, so the two halves of the surface agree).
 */
#[TypeScript]
class EventTypeDescriptorData extends Data
{
    public function __construct(
        #[Description('The full event name, plural-verbatim — the exact string an `events` array subscribes with.')]
        public string $name,

        #[Description('Segment one of the name: the live resource key this event hangs off.')]
        public string $resource,

        #[Description('Everything after segment one — `generate.completed` for `compositions.generate.completed`.')]
        public string $verbPhrase,

        #[Description('The morph alias of the model a delivery is about, or null when the event declared itself subjectless.')]
        public ?string $subject,

        #[Description('One line of prose describing when the event fires. Not a contract.')]
        public string $description,
    ) {}

    public static function fromEventType(EventType $type): self
    {
        return new self(
            name: $type->name,
            resource: $type->resourceKey(),
            verbPhrase: $type->verbPhrase(),
            subject: static::morphAlias($type->subject),
            description: $type->description,
        );
    }

    /**
     * The morph alias for a model class, falling back to the FQCN only when the host registered no
     * alias for it.
     *
     * The fallback is deliberate rather than a null: a host that never called `enforceMorphMap()`
     * genuinely has the FQCN as its `subject_type` on disk, so publishing anything else here would
     * make the catalog disagree with the records it describes.
     *
     * @param  class-string|null  $class
     */
    protected static function morphAlias(?string $class): ?string
    {
        if ($class === null || ! class_exists($class)) {
            return $class;
        }

        $alias = array_search($class, Relation::morphMap() ?: [], true);

        return $alias === false ? $class : (string) $alias;
    }
}
