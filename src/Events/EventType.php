<?php

namespace Splicewire\Beam\Events;

use Rushing\Popcorn\Registries\HasRegistryKey;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\RegistryKey;

/**
 * One publishable event type — a name a tenant may subscribe to, and what a delivery of it carries.
 *
 * Declared, never derived (api-surface-coherence ticket 14 §4). The whole point of a catalog is that
 * `GET /hooks/events` can enumerate the vocabulary *before* anything has fired, so nothing here is
 * inferred from a runtime dispatch.
 *
 * ## The name is `{resourceKey}.{verbPhrase}`, and the resource key is segment ONE
 *
 * `compositions.generate.completed` is the resource `compositions` plus the verb phrase
 * `generate.completed` — a verb phrase MAY be multi-segment, and the resource key is always exactly the
 * first segment (ticket 14 §3). That reading is what makes ticket 12's prefix scan — `GET /hooks/events`
 * filtered by event-name prefix — a segment-wise branch read rather than a string `str_starts_with`.
 *
 * Names are plural-verbatim: the prefix IS the live resource key, spelled the way the resource registry
 * spells it, with no singularisation and no interpolation. `tenant.provisioned` and
 * `composition.render.{$status}` were the pre-consumer spellings and were normalised away by
 * api-surface-coherence ticket 40.
 *
 * ## `subject` and `payload` are separate slots and only one of them ships today
 *
 * `subject` is the model class a delivery is ABOUT — the thing a subscriber filters on. It is declared
 * here rather than derived from the emitting code, because the emitter is a job or a listener and its
 * "subject" is whatever the author happened to have in scope.
 *
 * `payload` is the Data class describing the wire body. It ships `null` for every entry registered
 * today: the real shapes are authored by api-surface-coherence tickets 27/31, and an *undeclared*
 * payload is recorded rather than rejected (ticket 14 §5) — a catalog that refused to list an event
 * until someone wrote its DTO would be a catalog nobody could ship an event into.
 *
 * ## `subjectless` is the only escape hatch, and it ships unused
 *
 * An event with no subject is legal only when it SAYS so (ticket 13 §5). Nothing registered today sets
 * it; {@see EventTypeRegistry} refuses a subject-less entry that has not declared itself one, and
 * `EventTypeCatalogTest`'s allowlist of declared-subjectless names is empty and is a *shrinking*
 * allowlist, not a count ratchet. Adding the first entry to it is a decision, not a build step.
 *
 * House style: plain mutable class — no `strict_types`, no `final`, no `readonly`
 * (`docs/agents/php-style.convention.md`).
 */
class EventType implements HasRegistryKey
{
    /**
     * @param  string  $name  `{resourceKey}.{verbPhrase}`, plural-verbatim, e.g. `compositions.render.completed`
     * @param  class-string|null  $subject  the model a delivery is about, or null when {@see $subjectless}
     * @param  class-string|null  $payload  the Data class describing the wire body, or null while undeclared
     * @param  bool  $subjectless  this event genuinely has no subject, and says so
     * @param  string  $description  one line, for `GET /hooks/events` — prose, not a contract
     */
    public function __construct(
        public string $name,
        public ?string $subject = null,
        public ?string $payload = null,
        public bool $subjectless = false,
        public string $description = '',
    ) {}

    /**
     * The registry key IS the event name — {@see EventTypeRegistry} stamps its own root on the front, so
     * nothing here concatenates one.
     */
    public function registryKey(): RegistryKey|string
    {
        return $this->name;
    }

    /**
     * The resource key this event hangs off: segment one of the name, full stop.
     *
     * Returns `''` for a name that is not a legal key at all — callers here are the registry's own
     * validation, which reports that as its own failure with a better message than a parse error.
     */
    public function resourceKey(): string
    {
        $key = Key::tryParse($this->name);

        return $key === null ? '' : ($key->segments()[0] ?? '');
    }

    /** Everything after segment one — `generate.completed` for `compositions.generate.completed`. */
    public function verbPhrase(): string
    {
        $key = Key::tryParse($this->name);

        return $key === null ? '' : implode('.', array_slice($key->segments(), 1));
    }
}
