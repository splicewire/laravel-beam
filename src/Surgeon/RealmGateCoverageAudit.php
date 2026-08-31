<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Realm\RealmManifestProjector;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * A `beam.core.realm_gates` entry naming a realm nobody registers is **dead config**, and nothing anywhere
 * says so.
 *
 * {@see RealmManifestProjector::project()} iterates the REGISTERED realms and looks each one's gate up:
 *
 * ```php
 * foreach ($this->realms->all() as $key => $realm) {
 *     $gate = $gates[$key] ?? null;
 * ```
 *
 * The map is never iterated. So a gate keyed on a realm the {@see RealmRegistry} does not ship is read by
 * nothing, matched against nothing, and silently ignored — the loop simply never asks for that key.
 *
 * ## This is dead config, NOT an open door — and the distinction decides the severity
 *
 * The tempting reading is "a gate that never fires means a realm is unguarded." It does not. There is
 * nothing to leave unguarded: the realm does not exist, so the projector emits no descriptor for it and a
 * launcher has nothing to render. Today the entry is inert in the strongest sense.
 *
 * The risk is entirely **latent, and it inverts on the day the realm appears.** Register that realm later —
 * a capability package contributing through {@see RealmRegistry::register()}, an attributed `#[Realm]`
 * class, a host's own provider — and the forgotten gate springs to life against it. If its `mode` is
 * `hard` (the default when a gate declares no mode) the realm is OMITTED from every unentitled principal's
 * manifest, which is protection-by-absence: the new realm is simply not there, for everyone lacking an
 * entitlement nobody remembers declaring. That failure presents as "the realm I just registered does not
 * show up", which is a debugging session with no error message in it, three files away from the config
 * line that caused it.
 *
 * ## Zero live instances, which is the argument FOR the instrument
 *
 * Swept across the 16 beam-installing `~/Herd` roots on 2026-08-30: **four declare realm gates at all**
 * (`~/Herd/audiostud` gates `operator` and `tenant`; the `laravel-beam`, `laravel-satellite` and
 * `laravel-tower` starters each gate `operator`), twelve declare none — including `~/Herd/splicewire-app` —
 * and **not one orphan gate remains**.
 *
 * It was not zero a day earlier. All three starters shipped
 * `'os' => ['entitlement' => 'os.enter', 'mode' => 'hard']` for months, and {@see RealmRegistry} ships
 * exactly four realms — `operator`, `tenant`, `user`, `site`. There has never been an `os` realm in this
 * package. The three entries were removed by hand on 2026-08-30 (`48db4fd` / `744338f` / `26d66d2`), which
 * is precisely why this audit now measures zero: the defect was found by reading, and reading does not
 * recur. {@see ListedResourceDisplacementAudit} makes the same argument for its own zero-instance verdict —
 * a defect with no current instance needs an instrument rather than a memory.
 *
 * ## Advisory, non-negotiably
 *
 * Whether a realm is registered is a fact about the HOST — which capability packages it composes, and when
 * their providers boot. A host may legitimately register a realm AFTER config is loaded, so a boot-time
 * throw here would be an assertion about load order dressed up as an assertion about correctness. The
 * estate bought that rule with an outage: an event catalog that threw on an unregistered resource prefix
 * was true at the flagship and false at `~/Herd/tower`, where it meant the host could not boot at all.
 *
 * Everything is computed **on read**, never stamped at `register()`, so a realm contributed after beam's
 * own boot clears its gate's finding instead of having load order recorded as truth about it.
 *
 * ## The inverse is deliberately NOT reported
 *
 * A registered realm with no gate is the documented default — "an UNGATED realm always projects, unlocked"
 * — and twelve of the sixteen measured hosts gate nothing at all. Reporting it would be reporting that the
 * feature is switched off.
 */
class RealmGateCoverageAudit implements DoctorAudit
{
    /** A `beam.core.realm_gates` key naming a realm nothing registers: read by nothing, silently inert. */
    public const CHECK_ORPHANED = 'realm.gate.orphaned';

    /** The census line, emitted whether or not anything warned. */
    public const CHECK_CENSUS = 'realm.gate';

    /**
     * The config key the projector reads, spelled once so the two cannot drift.
     *
     * The projector's own read is `config('beam.core.realm_gates', config('beam.realm_gates', []))` — the
     * second is a legacy fallback, and {@see gates()} reproduces it verbatim — including the fact that the
     * fallback is UNREACHABLE. `config/beam/core.php` ships `'realm_gates' => []`, so once beam's package
     * config is merged the primary key always exists and Laravel never evaluates the default argument.
     * Reproduced anyway, because the one thing this audit must never do is disagree with the code it
     * audits: "prefer the legacy key when the primary is empty" would report an orphan against a map
     * nothing reads.
     */
    public const KEY = 'beam.core.realm_gates';

    public function __construct(
        protected RealmRegistry $realms,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $gates = $this->gates();
        $registered = $this->registeredRealms();

        // The population gate. A host with no gate map is not using the realm-entitlement axis, so there
        // is no entry that could be orphaned and nothing to measure — twelve of the sixteen beam-installing
        // Herd roots, the flagship among them, on 2026-08-30.
        if ($gates === []) {
            return [Finding::pass(
                self::CHECK_CENSUS,
                sprintf(
                    'This host declares no realm gates (%s is empty), so it does not use the realm '
                    .'entitlement axis and every one of its %d registered realm%s (%s) projects unlocked, '
                    .'as documented. Nothing was measured.',
                    self::KEY,
                    count($registered),
                    count($registered) === 1 ? '' : 's',
                    implode(', ', $registered),
                ),
            )];
        }

        $findings = [];

        foreach ($gates as $key => $gate) {
            $key = (string) $key;

            if (in_array($key, $registered, true)) {
                continue;
            }

            $findings[] = Finding::warn(
                self::CHECK_ORPHANED,
                sprintf(
                    '%s.%s gates a realm no RealmRegistry entry ships — this host registers %s. '
                    .'RealmManifestProjector::project() iterates the REGISTERED realms and reads '
                    .'$gates[$key], so it never asks for [%s] and the entry is dead config: %s. Nothing is '
                    .'left unguarded by it today, because there is no realm to guard. The cost is latent — '
                    .'register [%s] later and this %s gate fires against it, %s, with no error anywhere. '
                    .'Delete the line, or correct the key to one of the registered realms.',
                    self::KEY,
                    $key,
                    implode(', ', $registered),
                    $key,
                    $this->describe($gate),
                    $key,
                    $this->mode($gate),
                    $this->mode($gate) === 'hard'
                        ? 'omitting the realm entirely from every unentitled principal\'s manifest'
                        : 'projecting the realm locked with its upsell metadata for every unentitled principal',
                ),
            );
        }

        $orphans = count($findings);

        $findings[] = Finding::pass(
            self::CHECK_CENSUS,
            sprintf(
                '%d realm gate%s (%s) against %d registered realm%s (%s): %d live, %d orphaned.',
                count($gates),
                count($gates) === 1 ? '' : 's',
                implode(', ', array_map('strval', array_keys($gates))),
                count($registered),
                count($registered) === 1 ? '' : 's',
                implode(', ', $registered),
                count($gates) - $orphans,
                $orphans,
            ),
        );

        return $findings;
    }

    /**
     * The gate map exactly as {@see RealmManifestProjector::project()} resolves it.
     *
     * @return array<array-key, mixed>
     */
    protected function gates(): array
    {
        $gates = config(self::KEY, config('beam.realm_gates', []));

        return is_array($gates) ? $gates : [];
    }

    /**
     * The realm keys this host registers, read from the registry rather than from
     * {@see RealmRegistry::operator()} and friends: a capability package contributes realms through
     * `register()`, and hard-coding the four base ones would report every contributed realm as an orphan.
     *
     * @return list<string>
     */
    protected function registeredRealms(): array
    {
        return array_map('strval', array_keys($this->realms->all()));
    }

    /** The gate's declared mode, defaulted the way the projector defaults it. */
    protected function mode(mixed $gate): string
    {
        return is_array($gate) ? (string) ($gate['mode'] ?? 'hard') : 'hard';
    }

    /** What the dead line actually says, so the reader can decide delete-or-rename without opening config. */
    protected function describe(mixed $gate): string
    {
        if (! is_array($gate)) {
            return 'mode '.$this->mode($gate);
        }

        $entitlement = $gate['entitlement'] ?? null;

        return $entitlement === null
            ? 'mode '.$this->mode($gate).', no entitlement declared'
            : sprintf('entitlement [%s], mode %s', (string) $entitlement, $this->mode($gate));
    }
}
