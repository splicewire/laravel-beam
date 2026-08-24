<?php

namespace Splicewire\Beam\Codegen;

use Closure;
use Illuminate\Support\Str;
use ReflectionFunction;
use ReflectionNamedType;
use RuntimeException;
use Splicewire\Beam\Particle\Contribution\ContributionProjector;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The JOIN the contribution seam was missing: the owner's generated TypeScript type intersected with
 * every registered slice, derived from {@see ResourceContributionRegistry}
 * (particle-contribution-seam ticket 22).
 *
 * ## Why the owner's own type could not simply grow the key
 *
 * A slice rides spatie's `additional()` ({@see ContributionProjector}), whose keys the TypeScript
 * transformer cannot see — so `TenantCommerceData` generates a type of its own perfectly well while
 * `TenantData`'s type never grows a `commerce` key (ticket 04 §A4, accepted knowingly as the price of
 * the dependency-cycle guard). Widening the OWNER's emitted type in place would put a commerce-shaped
 * key on beam-tenancy's class in the generated tree — the same breach the guard exists to prevent, one
 * artifact over.
 *
 * So the join is a DERIVED type keyed by RESOURCE KEY, which is also the honest identity: a
 * contribution is registered against `tenants`, not against `TenantData`, and one Data class may serve
 * more than one key (the reason `with()` on a base class was rejected in ticket 04 §A4). `TenantData`
 * stays exactly what the PHP class says it is; `Splicewire.Beam.Particle.Read.Tenants` is what a
 * `tenants` READ returns.
 *
 * ## Absent vs. present-and-null survives into the type
 *
 * Both halves of ticket 04 §A7's null rule are structural here rather than defensive:
 *
 *  - a key is **absent from the emitted type** exactly when no contribution is registered for it, i.e.
 *    the contributing package is not installed in the host being generated for. Regenerate on a beam
 *    host with tenancy but no commerce and `Tenants` is `TenantData & {}` — no `commerce` key to reach
 *    for, so a stale read of one is a compile error rather than a runtime `undefined`;
 *  - a key is **present and `| null`** exactly when the contribution's value arm can return null.
 *
 * ⚠️ That second half is read off the value arm's DECLARED RETURN TYPE, not assumed. Ticket 22's brief
 * said "`| null` is not defensive — it is the declared contract", which is right in shape and wrong for
 * two of the estate's three contributions: `tenants.commerce` returns `?TenantCommerceData` (a tenant
 * with no plan), while `me.commerce` and `me.embed` both declare a NON-nullable return and their
 * docblocks argue the point explicitly ("a slice rather than null: commerce IS installed, and this
 * caller simply has no tenant-scoped decisions"). Emitting a blanket `| null` would force every consumer
 * through a null check the contract rules out. An UNDECLARED return type widens to nullable, because
 * nothing there can be proven.
 *
 * ## Pure by construction
 *
 * Registries in, TypeScript out — no filesystem, no config reads, no container. The command owns those
 * (the same split {@see TsClientGenerator} already uses), which is what lets the whole derivation be
 * asserted from a couple of hand-registered contributions in a test.
 */
class ContributedTypesGenerator
{
    /** The ambient namespace the derived read types land in — merged into the emitted global tree. */
    public const NAMESPACE = 'Splicewire.Beam.Particle.Read';

    public function __construct(
        protected ParticleResourceRegistry $resources,
        protected ResourceContributionRegistry $contributions,
    ) {}

    /**
     * Every contributed-to resource, resolved into the shape {@see render()} emits.
     *
     * A resource whose contributions are ALL includes-only yields nothing at all: an includes-only
     * contribution adds no key to the row ({@see ContributionProjector::apply()} skips it), so a derived
     * type for it would be `OwnerData & {}` — a name for a shape identical to the owner's, which is the
     * shadowing this map has now found at four levels.
     *
     * @return array<string, array{owner: class-string, slices: array<string, array{data: class-string, nullable: bool}>}>
     *                                                                                                                     keyed by resource key, registration order preserved
     */
    public function derive(): array
    {
        $derived = [];

        foreach ($this->contributions->keys() as $key) {
            $owner = $this->owner($key);

            if ($owner === null) {
                continue;
            }

            $slices = [];

            foreach ($this->contributions->for($key) as $contribution) {
                if ($contribution->value === null) {
                    continue;
                }

                $slices[$contribution->as] = [
                    'data' => $contribution->data,
                    'nullable' => $this->valueIsNullable($contribution),
                ];
            }

            if ($slices === []) {
                continue;
            }

            $derived[$key] = ['owner' => $owner, 'slices' => $slices];
        }

        return $derived;
    }

    /**
     * The owner resource's Data class, or null when nobody declares that key in this host.
     *
     * A contribution stores a resource KEY rather than a reference to a declaration precisely because
     * the contributor boots first (ticket 07), so a key with no declaration behind it is an ordinary
     * deployment fact — beam-commerce installed without beam-tenancy — and not an error. It contributes
     * to nothing at runtime, so it derives nothing here.
     *
     * @return class-string|null
     */
    protected function owner(string $key): ?string
    {
        if (! $this->resources->has($key)) {
            return null;
        }

        return $this->resources->get($key)->data;
    }

    /**
     * Whether a contribution's value arm can return null — read off its DECLARED return type.
     *
     * An undeclared return widens to nullable: absent a declaration there is nothing to prove, and the
     * type that makes a consumer check is the safe direction to be wrong in.
     */
    protected function valueIsNullable(ResourceContribution $contribution): bool
    {
        /** @var Closure $value */
        $value = $contribution->value;

        $type = (new ReflectionFunction($value))->getReturnType();

        if (! $type instanceof ReflectionNamedType) {
            return true;
        }

        return $type->allowsNull();
    }

    /**
     * Render the derived types as one ambient `.d.ts` body.
     *
     * @param  array<string, array{owner: class-string, slices: array<string, array{data: class-string, nullable: bool}>}>  $derived
     */
    public function render(array $derived): string
    {
        $body = '';

        foreach ($this->names($derived) as $key => $name) {
            $owner = $derived[$key]['owner'];
            $slices = $derived[$key]['slices'];

            $body .= "\n    /**\n"
                ."     * The `{$key}` read row — ".$this->reference($owner).' plus '
                .count($slices).' contributed '.(count($slices) === 1 ? 'slice' : 'slices').".\n"
                ."     *\n"
                ."     * A key is absent here exactly when its contributing package is not installed in the\n"
                ."     * host this was generated for; a key is `| null` exactly when the contribution's value\n"
                ."     * arm declares it can return null.\n"
                ."     */\n"
                ."    export type {$name} = ".$this->reference($owner)." & {\n";

            foreach ($slices as $as => $slice) {
                $body .= "        {$as}: ".$this->reference($slice['data']).($slice['nullable'] ? ' | null' : '').",\n";
            }

            $body .= "    };\n";
        }

        if ($body === '') {
            return $this->banner()
                ."// No resource in this host carries a value-bearing contribution, so there is nothing to derive.\n";
        }

        return $this->banner()
            .'declare namespace '.self::NAMESPACE." {\n"
            .$body
            ."}\n";
    }

    /**
     * The TypeScript type name per resource key.
     *
     * ⚠️ Two keys studly-casing to one name would silently drop a derived type, so it throws instead —
     * the same house rule the contribution registry itself applies to a duplicate `(key, as)`
     * (tickets 01, 09, 11): a declaration conflict surfaces at generation, not as a missing type on the
     * first read.
     *
     * @param  array<string, array{owner: class-string, slices: array<string, array{data: class-string, nullable: bool}>}>  $derived
     * @return array<string, string>
     */
    protected function names(array $derived): array
    {
        $names = [];

        foreach (array_keys($derived) as $key) {
            $name = Str::studly($key);

            if (($taken = array_search($name, $names, true)) !== false) {
                throw new RuntimeException(
                    "Contributed read types [{$taken}] and [{$key}] both derive the TypeScript name [{$name}]. "
                    .'Resource keys must stay distinct once studly-cased, or one derived type would silently '
                    .'overwrite the other.'
                );
            }

            $names[$key] = $name;
        }

        return $names;
    }

    /** e.g. `Splicewire\Beam\Tenancy\Data\TenantData` → `Splicewire.Beam.Tenancy.Data.TenantData`. */
    protected function reference(string $class): string
    {
        return str_replace('\\', '.', ltrim($class, '\\'));
    }

    /**
     * Every type this derivation references — the set the command checks against the emitted tree
     * before writing anything.
     *
     * @param  array<string, array{owner: class-string, slices: array<string, array{data: class-string, nullable: bool}>}>  $derived
     * @return list<string>
     */
    public function referencedTypes(array $derived): array
    {
        $referenced = [];

        foreach ($derived as $entry) {
            $referenced[] = $this->reference($entry['owner']);

            foreach ($entry['slices'] as $slice) {
                $referenced[] = $this->reference($slice['data']);
            }
        }

        return array_values(array_unique($referenced));
    }

    protected function banner(): string
    {
        return "// GENERATED by `splicewire:beam:generate:contributed-types` — do not edit.\n"
            ."// Derived from the particle contribution registry: each type is a resource's OWN read Data\n"
            ."// class intersected with every slice a package contributes to that resource key.\n\n";
    }
}
