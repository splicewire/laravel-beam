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
 * Three checks at `register()`, all throwing (api-surface-coherence ticket 14):
 *
 *  1. the name parses as a {@see Key} and carries at least two segments — a resource key and a verb
 *     phrase, the verb phrase possibly multi-segment;
 *  2. unless the entry declares {@see EventType::$subjectless}, it carries a subject;
 *  3. the name is not already taken ({@see OnDuplicate::Reject}).
 *
 * ## The fourth check is ADVISORY, and that is a correction (api-surface-coherence ticket 91)
 *
 * The prefix check — does the name's **first segment** resolve to a live resource key, per
 * {@see ResourceKeyOracle} (existence only, no model lookup)? — was a fourth throw here. It is now
 * {@see unresolvedPrefixes()}, read by `Splicewire\Beam\Doctor\EventCatalogPrefixAudit` and reported by
 * `splicewire:beam:doctor`.
 *
 * It is the only one of the four whose answer is a fact about the HOST rather than about the
 * declaration, and making a host-dependent fact fatal at boot took `~/Herd/tower` off the air the night
 * the catalog shipped: tower declares `compositions.generate.*` from its own provider and registers no
 * `compositions` particle resource, so a registry validation killed `artisan` outright — every command,
 * not merely the event surface. `Application::booted()` does not save it: tower's declaration was
 * ALREADY deferred to `booted()` and still threw, because the resource is absent at every point in that
 * host's lifecycle rather than merely registered later. Deferral fixes an ordering bug; this was never
 * one.
 *
 * The estate's posture is the tiebreaker — 62 of its 64 audits are advisory. A catalog entry whose
 * prefix is dead is a real defect, a subscription pointed at nothing, but it is a defect a doctor run
 * names with every other one beside it, not one that denies the operator a shell. The entry is
 * REGISTERED either way: refusing it would silently amputate a host's own declared vocabulary, which is
 * the worse failure of the two.
 *
 * The check is computed **on read, never stamped at registration**, which is what makes it honest across
 * the estate. A resource registered after the event that names it is the ordinary case in a
 * multi-package boot, and a flag taken at `register()` would record load order rather than truth.
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
        .'string prefix test. Registration validates the name grammar and subject-unless-subjectless '
        .'and throws; the prefix-against-LIVE-resources check is ADVISORY (`unresolvedPrefixes()`, '
        .'read by EventCatalogPrefixAudit) because it is host-dependent and it took a host off the air '
        .'as a throw — api-surface-coherence 91. Emission validates nothing. '
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
     * The registration-time checks that THROW: grammar, then subject. Both are facts about the
     * declaration itself, so both are answerable at the declaration site by the person who wrote it and
     * neither can change depending on which host loaded the provider. (Duplicate rejection is the
     * store's, via {@see OnDuplicate::Reject}.)
     *
     * The prefix check is deliberately absent — see the class docblock and {@see unresolvedPrefixes()}.
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

        if (! $type->subjectless && ($type->subject === null || $type->subject === '')) {
            throw new InvalidArgumentException(sprintf(
                'Event type [%s] declares no subject. Every event is about something; declare `subject:` '
                    .'(a model class-string), or say `subjectless: true` and add the name to the '
                    .'subjectless allowlist, which is a decision rather than a build step.',
                $type->name,
            ));
        }
    }

    /**
     * Every registered event name whose resource-key prefix is not a live resource on THIS host — the
     * advisory that replaced the boot-fatal fourth check (api-surface-coherence ticket 91).
     *
     * Computed here and now, over {@see all()} against a FORGOTTEN oracle cache, so the answer is the
     * estate as it stands at the moment somebody asks rather than the estate as it stood at whichever
     * provider happened to register first. That is the whole reason this is a read and not a flag.
     *
     * @return array<string, string> event name => the resource key that resolves to nothing, sorted by name
     */
    public function unresolvedPrefixes(): array
    {
        $oracle = $this->oracle();
        $oracle->forget();

        $unresolved = [];

        foreach ($this->all() as $type) {
            $resourceKey = $type->resourceKey();

            if (! $oracle->knows($resourceKey)) {
                $unresolved[$type->name] = $resourceKey;
            }
        }

        ksort($unresolved);

        return $unresolved;
    }

    /**
     * The live resource keys the prefix advisory is read against, sorted — what a diagnostic says WAS
     * available. Same forgotten-cache reading as {@see unresolvedPrefixes()}.
     *
     * @return list<string>
     */
    public function knownResourceKeys(): array
    {
        $oracle = $this->oracle();
        $oracle->forget();

        $known = $oracle->keys();
        sort($known);

        return array_values($known);
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
