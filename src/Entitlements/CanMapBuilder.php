<?php

namespace Splicewire\Beam\Entitlements;

use Illuminate\Contracts\Auth\Access\Gate;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Realm\RealmManifestProjector;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Frame OS ticket 08 (ADR-0013 §2): the flat `can` map builder. It projects the two-plane predicate into a
 * plain `Record<abilityString, bool>` the FRAME reads verbatim — the frontend performs NO authz
 * computation; it consumes this map and the projected manifest only.
 *
 * The map spans three key families:
 *  - FEATURE keys — every key of the {@see EntitlementKeyUniverse} (the one the `entitlement:{key}` abilities are
 *    defined from: `app.entitlements` ∪ `beam.core.entitlements.keys` ∪ contributed sources) ∪ any keys folded in
 *    with {@see withFeatureKeys()}, tested against the principal's holdings resolved ONCE (tutorial-dx 04);
 *  - APP/REALM keys — `app:{realmKey}` for each registered realm, true when the realm is REACHABLE by this
 *    principal (present in the projected manifest, whether unlocked or soft-locked; a hard-gated,
 *    unreachable realm is absent → false);
 *  - AGREEMENT keys (agreements 05) — `agreement:{key}` for each Agreement a party- or organisation-scoped
 *    Requirement names, asked of the Gate; absent when nothing declares a Requirement;
 *  - caller-supplied SUBJECT-scoped keys — window-mode ability strings like `song:{id}:update` the caller
 *    folds in via {@see with()}, each evaluated against the Laravel {@see Gate} (so subject policies decide
 *    them). The builder does not invent these keys — it only resolves the ones the caller passes.
 *
 * A pure builder: it returns an array and does NOT wire Inertia sharing (the host shares it — see the
 * example below). Inert by default: with no entitlements configured and the null resolver, feature/app keys
 * resolve false and the map is empty-but-correct.
 *
 * Host sharing example (left to the host / ticket 09):
 *   Inertia::share('can', fn () => app(CanMapBuilder::class)
 *       ->with(['song:'.$id.':update'])          // window-mode keys the current surface needs
 *       ->forPrincipal($request->user()));
 */
class CanMapBuilder
{
    /** @var list<string> extra ability strings folded into the next build */
    private array $extraAbilityChecks = [];

    /** @var list<string> extra feature keys beyond the entitlement key universe */
    private array $extraFeatureKeys = [];

    public function __construct(
        private EntitlementGate $entitlements,
        private RealmRegistry $realms,
        private RealmManifestProjector $manifest,
        private Gate $gate,
        private ?EntitlementKeyUniverse $universe = null,
    ) {}

    /**
     * Fold subject-scoped / window-mode ability strings (e.g. `song:{id}:update`) into the built map. Each
     * is resolved against the Laravel Gate for the principal. Returns a fresh builder so the base singleton
     * is never mutated across requests.
     *
     * @param  list<string>  $abilityStrings
     */
    public function with(array $abilityStrings): self
    {
        $clone = clone $this;
        $clone->extraAbilityChecks = array_values(array_unique([...$this->extraAbilityChecks, ...$abilityStrings]));

        return $clone;
    }

    /**
     * Additionally treat these keys as FEATURE abilities (beyond config('app.entitlements')) — the seam for
     * beam-known feature keys a host has not mirrored into the entitlement config.
     *
     * @param  list<string>  $featureKeys
     */
    public function withFeatureKeys(array $featureKeys): self
    {
        $clone = clone $this;
        $clone->extraFeatureKeys = array_values(array_unique([...$this->extraFeatureKeys, ...$featureKeys]));

        return $clone;
    }

    /**
     * Build the flat can-map for a principal.
     *
     * @return array<string, bool>
     */
    public function forPrincipal(mixed $principal): array
    {
        $can = [];

        // FEATURE plane — every key in the universe ∪ any folded-in keys, against holdings resolved once.
        $held = array_flip($this->entitlements->held($principal));
        foreach ($this->featureKeys() as $key) {
            $can[$key] = isset($held[$key]);
        }

        // APP/REALM plane — reachable = present in the projected manifest (unlocked OR soft-locked).
        $reachable = [];
        foreach ($this->manifest->project($principal) as $descriptor) {
            $reachable[$descriptor['key']] = true;
        }
        foreach (array_keys($this->realms->all()) as $realmKey) {
            $can['app:'.$realmKey] = isset($reachable[$realmKey]);
        }

        // AGREEMENT plane (agreements 05) — `agreement:{key}` for every distinct Agreement a party- or organisation-scoped
        // fixed-key Requirement names, asked of the Gate (an agreements seam defines the ability). Subject-scoped Requirements
        // are per record and stay out of a flat map; a resolver key also stays out because there is no subject here.
        // No Requirement anywhere ⇒ nothing added.
        foreach ($this->agreementKeys() as $key) {
            $can['agreement:'.$key] = $this->gate->forUser($principal)->allows('agreement:'.$key);
        }

        // SUBJECT-scoped / window-mode keys the caller folded in — resolved against the Laravel Gate.
        foreach ($this->extraAbilityChecks as $ability) {
            $can[$ability] = $this->gate->forUser($principal)->allows($ability);
        }

        return $can;
    }

    /** @return list<string> distinct Agreement keys of every party/organisation-scoped Requirement the port declares */
    private function agreementKeys(): array
    {
        if (! app()->bound(RequirementDeclarations::class)) {
            return [];
        }

        $keys = [];
        foreach (app(RequirementDeclarations::class)->all() as $requirements) {
            foreach ($requirements as $requirement) {
                if ($requirement instanceof Requires && in_array($requirement->scope, ['party', 'organisation'], true)) {
                    // A subject-aware key has no concrete value in this subject-free projection.
                    if ($requirement->agreement !== null) {
                        $keys[$requirement->agreement] = true;
                    }
                }
            }
        }

        return array_keys($keys);
    }

    /** @return list<string> the deduped feature-key universe */
    private function featureKeys(): array
    {
        return array_values(array_unique([
            ...($this->universe ?? app(EntitlementKeyUniverse::class))->keys(),
            ...$this->extraFeatureKeys,
        ]));
    }
}
