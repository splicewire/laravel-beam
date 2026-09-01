<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;

/**
 * The **central-pin resolvability** audit (beam-facade ticket 97, from ticket 79): every pin of a named
 * connection must point at a connection this host actually defines.
 *
 * ## Sibling, not an extension — and the reason is the other audit's own argument
 * {@see CentralPinJustificationAudit} asks whether a pin is *justified*; its output is a documentation
 * backlog, and it has argued at length for never emitting a `Fail` because "a documentation backlog that
 * fails the build is just a blocked build". This audit asks whether the pinned name **exists**, which has
 * no judgement in it at all: an unresolvable pin is `InvalidArgumentException: Database connection
 * [central] not configured.` the first time anything touches the model. Folding a hard finding into an
 * audit built around never emitting one would break that argument rather than extend it. So the census is
 * COMPOSED — {@see CentralPinJustificationAudit::pins()} already enumerates every pin in all three forms —
 * and only the verdict differs.
 *
 * ## One connection name, one lookup — the ticket's premise, corrected
 * Ticket 97 described the check as "one `config()` lookup per row, using the row's target connection name".
 * The rows do not carry one. `CentralPinJustificationAudit` matches the literal
 * {@see CentralPinJustificationAudit::CENTRAL} and nothing else, so **every row's target connection is
 * `central` by construction**, and a row's `targets` key holds the target MODEL classes, not a connection.
 * The check is therefore one lookup for the whole run, and the census exists to name WHICH files go down
 * when it fails — which is the useful half, because the repair is one line and the blast radius is twenty.
 * That is also why this class reads the name off the census's constant rather than declaring its own or
 * accepting a configured list: `central` is the only literal connection name pinned anywhere in the family
 * (79's census), and a configuration surface for names that do not exist is surface.
 *
 * ## What is left to fail, now that core registers the alias
 * `BeamServiceProvider::registerCentralConnectionAlias()` (ticket 96) copies the host's default connection
 * block to `central` at `register()` time, so in a current beam host this audit passes by construction. It
 * is not thereby vacuous — it covers exactly the three states the alias declines to repair, each of which
 * is a real host on this estate's history:
 *
 * 1. **The alias no-ops on purpose.** A host whose `database.default` IS `central` has nothing to copy
 *    from, and a host whose default connection has no config block is broken independently. Both are
 *    guarded no-ops in the provider, and both leave the pins unresolvable.
 * 2. **The host has not picked the alias up yet.** `~/Herd/beam-pilot-gcp-cloud-run` resolves beam from
 *    git, so it inherits ticket 96 on its next `composer update` and not before — the one root where this
 *    audit fires correctly today.
 * 3. **A future pin below the alias.** The alias runs in core's `register()`; anything resolving a pinned
 *    model earlier than that is back in the failure 79 repaired.
 *
 * ## Advisory, and NOT because the finding is soft
 * Every finding here is a {@see Finding::fail()} — an operator reading this is looking at a guaranteed
 * runtime fatal, and severity is what an operator reads. It nonetheless registers `gate: false`, for two
 * independent reasons, either of which alone is decisive:
 *
 * - `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md` puts the fatal line at *whose fact
 *   is it*. "Does connection `central` exist **here**" is a fact about the HOST, which the convention names
 *   as its textbook advisory case — and is the exact shape that took `~/Herd/tower` down when it was got
 *   backwards.
 * - Ticket 88 ruled the surgeon built-in channel advisory by contract at any severity, with the opt-in
 *   living in the host's own manifest. A foundation package may not decide what fails a root's build.
 *
 * A host that wants this to stop its build registers this class in its own doctor manifest with
 * `gate: true`; that is the supported route and it is the only one.
 *
 * ## Jurisdiction
 * This ships in beam-core and registers in beam's doctor manifest, so it reaches a host only if that host
 * installs beam AND runs the doctor. That is not the usual gap 19 recorded, because the population and the
 * jurisdiction coincide: every package that pins `central` requires beam-core (transitively — commerce →
 * tenancy → accounts, embed → commerce/tenancy → accounts, tower directly), so a root with no beam has no
 * pins to check. What it genuinely does not reach is a beam host that never installed
 * `rushing/laravel-surgeon`/the doctor command — there the check is absent, and the alias is the only thing
 * standing between that host and 79's failure.
 */
class CentralPinResolvabilityAudit implements DoctorAudit
{
    public const CHECK = 'tenancy.central-pin-resolvability';

    /**
     * @param  \Closure(string): bool|null  $resolves  How to ask whether a connection name is defined.
     *                                                 Null reads the live `database.connections` config,
     *                                                 which is the only production shape; the seam exists
     *                                                 so the failing case is testable without breaking a
     *                                                 host's database config out from under the suite.
     */
    public function __construct(
        protected CentralPinJustificationAudit $census,
        protected ?\Closure $resolves = null,
    ) {}

    /**
     * Host-scoped wiring: the same census scope the justification audit uses, so the two audits always
     * report over an identical population and a pin can never appear in one and not the other.
     */
    public static function forApp(?CentralPinJustificationAudit $census = null): self
    {
        return new self($census ?? CentralPinJustificationAudit::forApp());
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $connection = CentralPinJustificationAudit::CENTRAL;
        $pins = $this->census->pins();

        if ($pins === []) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'No pins of the [%s] connection in scope, so nothing to resolve.',
                $connection,
            ))];
        }

        if ($this->connectionIsDefined($connection)) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d pin(s) of the [%s] connection resolve — the connection is defined at this host.',
                count($pins),
                $connection,
            ))];
        }

        return array_map(
            fn (array $row) => Finding::fail(self::CHECK, $this->detail($row, $connection)),
            $pins,
        );
    }

    /**
     * The work-list: every pin whose target connection this host does not define. Empty when the
     * connection resolves, and the whole census when it does not — one cause, N casualties.
     *
     * @return list<array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}>
     */
    public function unresolvable(): array
    {
        return $this->connectionIsDefined(CentralPinJustificationAudit::CENTRAL)
            ? []
            : $this->census->pins();
    }

    /** Whether this host defines a connection block under the given name. */
    public function connectionIsDefined(string $connection): bool
    {
        if ($this->resolves !== null) {
            return ($this->resolves)($connection);
        }

        return config("database.connections.{$connection}") !== null;
    }

    /**
     * The finding text. It names the file, the pin FORM and the connection — the three things the ticket's
     * acceptance asks for — and then names the single shared cause, because a reader looking at twenty of
     * these needs to know they are one repair and not twenty.
     *
     * @param  array{class: string, file: string, line: int, form: string, targets: list<string>, citation: string|null, justified: bool}  $row
     */
    protected function detail(array $row, string $connection): string
    {
        return sprintf(
            '%s pins the [%s] connection (%s form) at %s:%d, and this host defines no such connection — '.
            'touching it throws `Database connection [%s] not configured.`. This is ONE cause with one repair, '.
            'reported per pin so the blast radius is visible: either define a [%s] connection block, or update '.
            'splicewire/laravel-beam so its provider aliases [%s] onto this host\'s default connection.',
            $this->shortName($row['class']),
            $connection,
            $row['form'],
            $row['file'],
            $row['line'],
            $connection,
            $connection,
            $connection,
        );
    }

    protected function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
