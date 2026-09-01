<?php

namespace Splicewire\Beam\Surgeon;

use BackedEnum;
use Closure;
use ReflectionObject;
use ReflectionProperty;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Registries\RecordsSupersession;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\Superseded;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use UnitEnum;

/**
 * Every particle key whose registration displaced an earlier one, classified by WHAT it displaced.
 *
 * ## The mechanism produced the evidence and nothing read it
 *
 * `ParticleResourceRegistry` and `ParticleOperationRegistry` are both
 * `onDuplicate: OnDuplicate::Supersede`, and both record every loser through popcorn's
 * {@see Superseded}. Until this audit, `superseded()` had **zero call sites in the estate outside
 * popcorn itself** — the registries were keeping a complete history of every shadowed declaration that
 * no instrument ever asked for. Measured at the booted `~/Herd/splicewire-app` on 2026-08-31: **21 of
 * 53 resource keys and 10 of 36 operation keys carry a displaced entry**, i.e. 40% of the flagship's
 * resource surface is a contest with a silent winner, and nothing said so.
 *
 * ## Why the classification is the whole audit
 *
 * A bare "was it displaced?" check fires on all 21 and is mostly noise, which is the same lesson
 * {@see ListedResourceDisplacementAudit} learned one population over. The identity of the DISPLACED
 * entry is the signal, and there are three verdicts:
 *
 *  - **`*.superseded.divergent`** — the displaced declaration differs from the winner on at least one
 *    field. Something real is being lost or changed by arrival order alone, and the fields are named
 *    so a reader can decide rather than guess. This is the finding.
 *  - **`*.superseded.redundant`** — every displaced entry is field-for-field IDENTICAL to the winner.
 *    The re-registration changes nothing but the supersession record; the line can be deleted. Reported
 *    as a `Pass` that NAMES the keys, so a repair can enumerate them without the audit reddening a host
 *    over deletable lines.
 *  - **nothing superseded** — the registration is the only one at that key. Load-bearing, and silent.
 *
 * ## ⚠️ The comparison is FIELD-level, and that is not a refinement of the class-level one — it
 * disagrees with it
 *
 * The obvious implementation compares `ParticleResource::$data` (the Data class) and calls two
 * registrations of the same class redundant. Measured both ways at the flagship on 2026-08-31:
 *
 * | comparison | redundant | divergent |
 * |---|---|---|
 * | by Data class | 18 | 3 |
 * | **by field** | **7** | **14** |
 *
 * The operation registry splits the other way and is worth stating so the two are not confused: all 10
 * of its displaced keys are redundant and none divergent — `hooks.reset`, five `calendars.*`, two
 * `open-api-specs.*` and two `invitations.*` are each registered twice with identical declarations.
 *
 * Eleven keys name the identical Data class and still differ — on `group`, `includes`, `input`,
 * `showable`, `query`, `createAffordance`, or on carrying a `project`/`prepare`/`afterWrite` closure the
 * displaced entry did not. A class-name-only audit would have authorised deleting all eleven, and the
 * deletion would have silently dropped a nav group, an eager-load set, and a projection closure behind a
 * green suite. Two entries naming the same class are not the same declaration.
 *
 * ## What a field diff can and cannot see
 *
 * Every public property is compared by {@see fingerprint()}. Closures are compared by **presence only**
 * — two closures are never comparable in PHP, and "one has a `project` and the other does not" is the
 * question that actually decides a deletion. Two declarations that both carry a `project` closure with
 * different bodies read as identical here, which is a real blind spot and is stated rather than hidden:
 * this audit narrows the candidate set for deletion, it does not certify a deletion.
 *
 * ## Advisory, permanently
 *
 * Whether a host shadows a package's declaration is a fact about which packages that host composes and
 * in what provider order — never grammar the declaration's author could have gotten right. By this
 * estate's rule such a check reports and does not throw; `~/Herd/tower`, which takes beam's defaults,
 * carries exactly ONE superseded resource key and zero superseded operations, and a gate here would be
 * making a statement about the flagship's composition at every other host. A host that wants it to gate
 * registers this class in its own manifest with `gate: true`.
 */
class SupersededDeclarationAudit implements DoctorAudit
{
    public function __construct(
        protected ParticleResourceRegistry $resources,
        protected ParticleOperationRegistry $operations,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return [
            ...$this->sweep('resource', $this->resources, 'particle resource'),
            ...$this->sweep('operation', $this->operations, 'particle operation'),
        ];
    }

    /**
     * One registry's worth of verdicts.
     *
     * Typed on the popcorn interfaces rather than on either concrete registry: the two share `keys()`,
     * `tryResolve()` and `superseded()` and nothing else this audit touches, so the sweep is written
     * once and the acceptance item asking whether operations are covered answers itself.
     *
     * @param  Registry&RecordsSupersession  $registry
     * @return list<Finding>
     */
    protected function sweep(string $check, Registry $registry, string $noun): array
    {
        $keys = $registry->keys();

        if ($keys === []) {
            return [Finding::inconclusive(
                "{$check}.superseded",
                "No {$noun}s are registered in this host, so no registration can have displaced another. "
                .'Nothing was measured.'
            )];
        }

        $findings = [];
        $redundant = [];
        $sole = 0;
        $divergent = 0;
        $unresolved = 0;

        foreach ($keys as $key) {
            $address = (string) $key;
            $displaced = $registry->superseded($key);

            if ($displaced === []) {
                $sole++;

                continue;
            }

            $winner = $registry->tryResolve($key);

            if ($winner === null) {
                // A key whose history survives its entry. Believed UNREACHABLE today and kept anyway:
                // popcorn's Authorizer filters `keys()` on the same rule as `tryResolve()`, so a gated
                // entry never reaches this loop, and `forget()` clears the supersession record with the
                // entry precisely to avoid the leak. Both of those are somebody else's invariants.
                //
                // ⚠️ It is counted rather than dropped because the census used to derive `divergent` by
                // subtracting sole and redundant from the key count — so had this branch ever fired, the
                // comment refusing to invent a verdict would have been followed by arithmetic inventing
                // one, and the audit would have reported a phantom contest. Count what you saw; never
                // reconstruct a class by subtraction.
                $unresolved++;

                continue;
            }

            $fields = $this->divergentFields($winner, $displaced);

            if ($fields === []) {
                $redundant[] = $address;

                continue;
            }

            $divergent++;
            $findings[] = Finding::warn(
                "{$check}.superseded.divergent",
                sprintf(
                    '[%s] displaced %d earlier %s registration%s differing on: %s. Arrival order alone '
                    .'decides which of these the host serves — %s. Delete the losing registration, or '
                    .'state why the shadow is intended.',
                    $address,
                    count($displaced),
                    $noun,
                    count($displaced) === 1 ? '' : 's',
                    implode(', ', $fields),
                    $this->provenance($displaced),
                )
            );
        }

        if ($redundant !== []) {
            $findings[] = Finding::pass(
                "{$check}.superseded.redundant",
                sprintf(
                    '%d %s key%s re-registered field-for-field identically, so the displacement changes '
                    .'nothing and the losing line can be deleted: %s.',
                    count($redundant),
                    $noun,
                    count($redundant) === 1 ? ' was' : 's were',
                    implode(', ', $redundant),
                )
            );
        }

        $findings[] = Finding::pass(
            "{$check}.superseded",
            sprintf(
                '%d %s key%s: %d sole registration%s, %d redundantly re-registered, %d divergent (a real '
                .'contest decided by provider boot order)%s.',
                count($keys),
                $noun,
                count($keys) === 1 ? '' : 's',
                $sole,
                $sole === 1 ? '' : 's',
                count($redundant),
                $divergent,
                $unresolved === 0
                    ? ''
                    : sprintf(', %d displaced with no resolvable winner (gated or forgotten) and left '
                        .'unclassified', $unresolved),
            )
        );

        return $findings;
    }

    /**
     * The public properties on which ANY displaced entry differs from the winner, in declaration order.
     *
     * Reflection over the winner rather than a hand-kept field list, because the list this would have
     * to keep is `ParticleResource`'s 28-slot constructor plus `ParticleOperation`'s 15 — and a slot
     * added later that the list did not learn about would make the audit quietly report a divergence as
     * redundant, which is the exact direction of error that authorises a wrong deletion.
     *
     * @param  list<Superseded>  $displaced
     * @return list<string>
     */
    protected function divergentFields(mixed $winner, array $displaced): array
    {
        if (! is_object($winner)) {
            // ⚠️ This returned `[]` — i.e. "no fields differ" — which the caller reads as REDUNDANT and
            // reports as "the losing line can be deleted", having compared nothing at all. That is the
            // wrong-direction failure this class's docblock warns about, produced by the audit itself:
            // an unreadable winner is the LEAST safe key to authorise a deletion on. Unreachable today
            // (both registries store objects and reject anything else at `register()`), and reported
            // rather than trusted, because the cost of being wrong is asymmetric.
            return ['<winner is not a declaration object>'];
        }

        $fields = [];

        foreach ($displaced as $loser) {
            $entry = $loser->entry;

            if (! is_object($entry) || $entry::class !== $winner::class) {
                // Different declaration TYPES at one key is a divergence no field walk describes.
                $fields['<declaration type>'] = true;

                continue;
            }

            foreach ($this->properties($winner) as $name) {
                $winning = $this->fingerprint($winner->{$name});
                $losing = $this->fingerprint($entry->{$name});

                if ($winning === $losing) {
                    continue;
                }

                // Name the two sides for the identity slots — which class won is the whole question on a
                // contested key, and a bare field name would send the reader back to the registry to
                // find out. Everything else is reported by name; the values are frequently closures,
                // long include lists, or nav integers that would swamp the finding.
                $fields[in_array($name, ['data', 'backing', 'input', 'editData'], true)
                    ? sprintf('%s (%s over %s)', $name, ...$this->contrast($winning, $losing))
                    : $name] = true;
            }
        }

        return array_keys($fields);
    }

    /**
     * The public, non-static property names of a declaration, declaration order.
     *
     * @return list<string>
     */
    protected function properties(object $declaration): array
    {
        return array_values(array_map(
            fn (ReflectionProperty $property) => $property->getName(),
            array_filter(
                (new ReflectionObject($declaration))->getProperties(ReflectionProperty::IS_PUBLIC),
                // Statics are class state, not declaration state: two entries at one key share them by
                // construction, so comparing them can only ever report "identical" — and reading one
                // through `$instance->{$name}` is an error in PHP 8.
                fn (ReflectionProperty $property) => ! $property->isStatic(),
            ),
        ));
    }

    /**
     * A comparable rendering of one property value.
     *
     * ⚠️ A {@see Closure} renders as its PRESENCE and nothing more. Two closures are never comparable in
     * PHP — no identity, no body, no stable spl hash across two independently constructed declarations —
     * and the question that decides a deletion is whether the losing entry carried one at all. The cost
     * is stated in the class docblock: two declarations that both carry a `project` read as identical
     * here even when the bodies differ.
     */
    protected function fingerprint(mixed $value): string
    {
        if ($value instanceof Closure) {
            return 'closure';
        }

        if ($value instanceof BackedEnum) {
            return $value::class.'::'.$value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value::class.'::'.$value->name;
        }

        if (is_object($value)) {
            // A declaration may hold a live collaborator (a `ResourceBacking` instance, a delivery
            // object). Its CLASS is the comparable fact; two instances of the same backing class are
            // the same declaration for this audit's purposes.
            return 'object<'.$value::class.'>';
        }

        if (is_array($value)) {
            return '['.implode(', ', array_map(
                fn ($key, $item) => $key.'=>'.$this->fingerprint($item),
                array_keys($value),
                $value,
            )).']';
        }

        return get_debug_type($value).':'.var_export($value, true);
    }

    /**
     * The two sides of an identity slot, shortened to basenames — but only while the basenames still
     * TELL THEM APART.
     *
     * ⚠️ Measured at the flagship on 2026-08-31, and it is why this is not a plain `shortly()` on each
     * side: `tokens` contests `Splicewire\Tower\Models\PersonalAccessToken` against
     * `Laravel\Sanctum\PersonalAccessToken`, and `members` contests two `MembershipSource` classes from
     * different packages. Shortening both produced `backing (PersonalAccessToken over
     * PersonalAccessToken)` — a finding that reads as a bug in the audit and hides the one fact the
     * reader needs. Where the basenames collide, both sides print in full.
     *
     * @return array{0: string, 1: string}
     */
    protected function contrast(string $winning, string $losing): array
    {
        $short = [$this->shortly($winning), $this->shortly($losing)];

        return $short[0] === $short[1] ? [$this->bare($winning), $this->bare($losing)] : $short;
    }

    /** A fingerprint with its `type:` prefix and `var_export` quoting/escaping taken back off. */
    protected function bare(string $fingerprint): string
    {
        $bare = str_contains($fingerprint, ':') ? substr($fingerprint, strpos($fingerprint, ':') + 1) : $fingerprint;

        return str_replace('\\\\', '\\', trim($bare, "'"));
    }

    /** The tail of a fingerprint — a class-string's basename, so a finding stays readable. */
    protected function shortly(string $fingerprint): string
    {
        $bare = $this->bare($fingerprint);

        return str_contains($bare, '\\') ? substr($bare, strrpos($bare, '\\') + 1) : $bare;
    }

    /**
     * Who registered the losing entries — the half of {@see Superseded} that answers "whose line do I
     * delete". Null registrants (a hand `register()` naming nobody) render as `unattributed`.
     *
     * @param  list<Superseded>  $displaced
     */
    protected function provenance(array $displaced): string
    {
        $by = array_values(array_unique(array_map(
            fn (Superseded $entry) => $entry->by ?? 'unattributed',
            $displaced,
        )));

        return 'displaced entries registered by '.implode(', ', $by);
    }
}
