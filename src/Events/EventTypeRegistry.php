<?php

namespace Splicewire\Beam\Events;

use InvalidArgumentException;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\Filled;
use Rushing\Popcorn\Registries\Gated;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\Key;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Optionality;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * The catalog of publishable event types — the vocabulary `GET /hooks/events` enumerates and a
 * subscribe call looks a name up in.
 *
 * ## It composes the kernel; it does not hand-roll a list
 *
 * A {@see BasicRegistry} held as a FIELD, per registry-kernel ticket 01 D1, exactly as
 * {@see ParticleOperationRegistry} does. The event name self-keys
 * ({@see EventType::registryKey()}), so `register($type)` stays one argument and nothing here
 * concatenates a key. The root is stamped by the store, so `compositions.render.completed` is stored,
 * enumerated and addressed as `beam.events.types.compositions.render.completed` and a rekey is a
 * one-line attribute change.
 *
 * ⚠️ **The delegation trap, and why {@see attach()} is written out below.** `BasicRegistry::attach()`
 * hands the registrar the STORE, so `$this->entries->attach($r)` would let a registrar write past this
 * class's own `register()` — and with it past every one of the validations this registry exists for. A
 * composing owner attaches to ITSELF and keeps its own registrar list; only the eagerness is inherited.
 * Measured on `ParticleResourceRegistry` first; re-stated here because the bug is silent.
 *
 * ## Registration is the only gate; emission never refuses
 *
 * Four checks, all at `register()`, all throwing (api-surface-coherence ticket 14):
 *
 *  1. the name parses as a {@see Key} and carries at least two segments — a resource key and a verb
 *     phrase, the verb phrase possibly multi-segment;
 *  2. its **first segment** resolves to a live resource key, checked across the resource registries by
 *     {@see ResourceKeyOracle} — existence only, no model lookup. This is the check that makes a
 *     resource-key rename propagate into the event vocabulary instead of orphaning stored
 *     subscriptions;
 *  3. unless the entry declares {@see EventType::$subjectless}, it carries a subject;
 *  4. the name is not already taken ({@see OnDuplicate::Reject}).
 *
 * Nothing validates at *emission*. A producer that fires an unregistered name is a producer whose
 * delivery finds no subscribers — a catalog miss, not a 500 in the middle of somebody's job. The
 * asymmetry is deliberate: the declaration site is where a human can fix the mistake.
 *
 * ## `Reject`, not `Supersede`
 *
 * Two providers claiming one event name is not an override, it is two teams believing they own a wire
 * contract. There is no "the later one wins" reading of that which is safe for a subscriber, so the
 * duplicate is loud. (The registries that ship `Supersede` do so because overriding *is* the feature —
 * a package replacing an operation it does not own. Nothing overrides an event type; it declares a new
 * one.)
 *
 * ## Enumerable without booting HTTP
 *
 * `all()` returns DATA, in registration order, with no read-time generation — ticket 13 §7's totality
 * assertion has to guard the catalog rather than guard a generator that recomputes the assertion's own
 * premise. Multi-name producers (`{status}` interpolations, the per-resource `*.persisted` fan-out) are
 * expanded at REGISTRATION, by their owners, into flat literal entries.
 */
#[IsRegistry(
    root: 'beam.events.types',
    of: 'publishable event types — enumerated by GET /hooks/events, looked up by name at subscribe time',
    arity: RegistryArity::PickOne,
    entryType: EventType::class,
    onDuplicate: OnDuplicate::Reject,
    optionality: Optionality::Optional,
    note: 'Keys ARE event names (`{resourceKey}.{verbPhrase}`, plural-verbatim) under the stamped root, '
        .'off the self-keying entry. The resource key is segment ONE and the verb phrase may be '
        .'multi-segment, so `withPrefix(\'compositions\')` is a segment-wise branch read rather than a '
        .'string prefix test. Registration validates the name grammar, the prefix against the LIVE '
        .'resource registries, and subject-unless-subjectless; emission validates nothing. '
        .'⚠️ The chartering ticket (api-surface-coherence 40 §4) specified a `describe(new '
        .'ManifestDescriptor(seam: ManifestSeam::SingletonAccumulator, registerHint: …, where: …))` — '
        .'that whole vocabulary was DELETED by registry-kernel ticket 21/07, and this attribute plus '
        .'`RegistryIndex::describe()` in BeamServiceProvider::boot() is its successor.',
    order: 14,
)]
class EventTypeRegistry implements Filled, Gated, Registry
{
    /** @var BasicRegistry<EventType> */
    private BasicRegistry $entries;

    /** @var list<Registrar> */
    private array $registrars = [];

    public function __construct(private ?ResourceKeyOracle $resources = null)
    {
        $this->entries = BasicRegistry::for($this);
    }

    /** @return BasicRegistry<EventType> */
    private function store(): BasicRegistry
    {
        return $this->entries;
    }

    // ── The event vocabulary ────────────────────────────────────────────────────────────────────────

    /**
     * Every registered event type, registration order.
     *
     * @return list<EventType>
     */
    public function all(): array
    {
        return $this->store()->matches($this->store()->root());
    }

    /**
     * Every event type whose resource key is `$resourceKey`, registration order — the read behind
     * `GET /hooks/events?resource=…` (api-surface-coherence ticket 12 §3).
     *
     * A branch read, not a string test: `withPrefix('composition')` returns nothing for
     * `compositions.render.completed`, which a `str_starts_with` would wrongly match.
     *
     * @return list<EventType>
     */
    public function withPrefix(string $resourceKey): array
    {
        if (Key::tryParse($resourceKey) === null) {
            return [];
        }

        return $this->store()->matches($resourceKey);
    }

    /** One event type by name, or null — the subscribe-time lookup, which ASKS rather than demands. */
    public function find(string $name): ?EventType
    {
        if (Key::tryParse($name) === null) {
            return null;
        }

        return $this->store()->tryResolve($name);
    }

    public function has(RegistryKey|string $name): bool
    {
        if (is_string($name) && Key::tryParse($name) === null) {
            return false;
        }

        return $this->store()->has($name);
    }

    /** @return list<string> every registered event NAME, registration order */
    public function names(): array
    {
        return array_values(array_map(fn (EventType $type) => $type->name, $this->all()));
    }

    // ── Registration — the only gate ────────────────────────────────────────────────────────────────

    /**
     * Store an event type. `register($type)` is the ordinary spelling; the contract spelling
     * `register('tenants.provisioned', $type, by: …)` works too, which is what lets a {@see Registrar}
     * fill this registry at all.
     *
     * @param  RegistryKey|string|EventType  $key  the event name, or the self-keying declaration
     */
    public function register(RegistryKey|string|EventType $key, mixed $entry = null, ?string $by = null, ?string $ability = null): static
    {
        if ($key instanceof EventType) {
            $type = $key;
        } else {
            $type = $entry;

            if (! $type instanceof EventType) {
                throw new InvalidArgumentException(sprintf(
                    'EventTypeRegistry stores %s declarations; `%s` was given for name [%s].',
                    EventType::class,
                    get_debug_type($entry),
                    (string) $key,
                ));
            }
        }

        $this->assertValid($type);

        $this->store()->register($type->registryKey(), $type, $by, $ability);

        return $this;
    }

    /**
     * The four registration-time checks, in the order that produces the most useful message: grammar
     * before prefix (an unparseable name has no prefix to look up), prefix before subject (a name
     * pointing at nothing is the bigger defect).
     */
    private function assertValid(EventType $type): void
    {
        $parsed = Key::tryParse($type->name);

        if ($parsed === null || count($parsed->segments()) < 2) {
            throw new InvalidArgumentException(sprintf(
                'Event name [%s] is not a legal event type name. The grammar is `{resourceKey}.{verbPhrase}` '
                    .'— dot-separated lowercase segments, at least two of them, the first being a live '
                    .'resource key and the rest a possibly-multi-segment verb phrase (e.g. '
                    .'`compositions.render.completed`).',
                $type->name,
            ));
        }

        $resourceKey = $type->resourceKey();

        if (! $this->oracle()->knows($resourceKey)) {
            $known = $this->oracle()->keys();
            sort($known);

            throw new InvalidArgumentException(sprintf(
                'Event name [%s] hangs off resource key [%s], which is not registered anywhere. An event '
                    .'whose prefix is not a live resource is a subscription pointed at nothing. Known keys: %s.',
                $type->name,
                $resourceKey,
                $known === [] ? '(none — no resource registry is populated yet)' : implode(', ', $known),
            ));
        }

        if (! $type->subjectless && ($type->subject === null || $type->subject === '')) {
            throw new InvalidArgumentException(sprintf(
                'Event type [%s] declares no subject. Every event is about something; declare `subject:` '
                    .'(a model class-string), or say `subjectless: true` and add the name to the '
                    .'subjectless allowlist, which is a decision rather than a build step.',
                $type->name,
            ));
        }
    }

    private function oracle(): ResourceKeyOracle
    {
        return $this->resources ??= new ResourceKeyOracle(app());
    }

    // ── The kernel contract ─────────────────────────────────────────────────────────────────────────

    /**
     * Attach a registrar and let it fill THIS registry — not the composed store. See the class docblock:
     * delegating to `$this->entries->attach()` would route every registrar write past
     * {@see assertValid()}, which is this registry's entire reason to exist.
     */
    public function attach(Registrar $registrar): void
    {
        $this->registrars[] = $registrar;

        $registrar->fill($this);
    }

    /** @return list<Registrar> */
    public function registrars(): array
    {
        return $this->registrars;
    }

    public function resolve(RegistryKey|string $key): mixed
    {
        return $this->store()->resolve($key);
    }

    public function tryResolve(RegistryKey|string $key): mixed
    {
        return $this->store()->tryResolve($key);
    }

    /** @return list<EventType> */
    public function matches(RegistryKey|string $key): array
    {
        return $this->store()->matches($key);
    }

    /** @return list<RegistryKey> */
    public function keys(): array
    {
        return $this->store()->keys();
    }

    public function unfiltered(): Registry
    {
        $unfiltered = clone $this;
        $unfiltered->entries = $this->store()->unfiltered();

        return $unfiltered;
    }

    public function authorizeWith(?Authorizer $authorizer): static
    {
        $this->store()->authorizeWith($authorizer);

        return $this;
    }
}
