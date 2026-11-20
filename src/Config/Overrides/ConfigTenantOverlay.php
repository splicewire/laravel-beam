<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Schemastud\DataSchemas\Overlay\JsonPathMutator;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Throwable;
use WeakMap;

/**
 * Applies the TENANT tier's published overrides inside a tenant hop, on top of the central effective configuration, and
 * reverts them when the hop ends (ADR-0245; config-sections ticket 07; PRD §Tenant tier). beam-tenancy's
 * `ConfigLadderTenancyBootstrapper` calls {@see apply()} on bootstrap and {@see revert()} on revert; core names no
 * tenancy library.
 *
 * **03's rule.** It never holds a config repository: every call resolves `app('config')`, and what it applied is
 * remembered per repository in a WeakMap keyed by the repository object, whose values are the CENTRAL values of the
 * roots it touched (values only, captured just before the tenant layer was written). Under Octane each request runs on
 * a clone, so a hop on one clone never writes the worker's base; a queue worker has no clone, so revert restores
 * exactly what was there.
 *
 * **Revert restores the central effective value**, the operator tier included, never the package default: the
 * captured values are what the repository held after the operator sync, and nothing below that is recomputed.
 *
 * **The tenant tier is read afresh on every hop** (two queries on the tenant connection and one for the operator's
 * locks on central, per section with a published tenant layer), so a tenant publish reaches that tenant's next request
 * or job with no counter, and nothing tenant-scoped is ever cached across tenants.
 *
 * **What it skips, at every depth ({@see restrict()}).** An entry whose key's declared ceiling no longer admits the tenant
 * tier, or that an operator lock covers, is not applied; inside an object entry, a locked or ceiling-narrowed child is
 * cut out and inherited from central. A lock narrows the tenant tier at once, whatever the tenant published before it.
 *
 * **Never throws.** A tenant store that cannot be read leaves the hop on the central effective values and logs why.
 *
 * **Operator sync during a hop.** {@see ConfigOverlay} lifts this layer before it writes the operator tier and lays it
 * back after ({@see lift()}, {@see lay()}), so the operator's base never captures a tenant's value and a tenant's value
 * survives an operator re-apply, whichever of the two `JobProcessing` listeners runs first.
 */
class ConfigTenantOverlay
{
    /** @var WeakMap<Repository, array{central: array<string, mixed>, layers: array<string, array<string, ConfigOverrideEntry>>}> */
    protected WeakMap $applied;

    public function __construct(protected Container $container)
    {
        $this->applied = new WeakMap;
    }

    /** Write the current tenant's published layer into the CURRENT repository. A no-op outside a tenant. Never throws. */
    public function apply(): void
    {
        $config = app('config');
        $this->restore($config);
        // A new tenant frame: the store's tenant-target answers are asked afresh.
        $this->forgetTargets();

        if (! $this->tenancyActive()) {
            return;
        }

        try {
            $layers = $this->layers();
        } catch (Throwable $e) {
            Log::warning('[beam] tenant config overrides not applied: the tenant override store could not be read; the tenant runs on the central values.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $this->lay($config, $layers);
    }

    /** Put the central values back into the CURRENT repository. A no-op when nothing was applied to it. */
    public function revert(): void
    {
        $this->restore(app('config'));
        $this->forgetTargets();
    }

    protected function forgetTargets(): void
    {
        try {
            $this->container->make(ConfigOverrideStore::class)->forgetTenantTargets();
        } catch (Throwable) {
            // A boot without the store has no answers to forget.
        }
    }

    /** Re-read and re-apply the tenant layer in the current repository (after a tenant publish in this process). */
    public function refresh(): void
    {
        $this->apply();
    }

    /** Whether the current repository holds a tenant layer. */
    public function active(): bool
    {
        return isset($this->applied[app('config')]);
    }

    /** A root's CENTRAL effective value (operator tier included, tenant tier excluded) in the current repository. */
    public function central(string $root): mixed
    {
        $config = app('config');
        $held = $this->applied[$config] ?? null;

        return $held !== null && array_key_exists($root, $held['central']) ? $held['central'][$root] : $config->get($root);
    }

    /**
     * Take this layer out of `$config` (restoring the central values) and hand back what it held, or null when nothing
     * was applied to it. {@see ConfigOverlay} calls it before writing the operator tier.
     *
     * @return array<string, array<string, ConfigOverrideEntry>>|null
     */
    public function lift(Repository $config): ?array
    {
        $held = $this->applied[$config] ?? null;

        if ($held === null) {
            return null;
        }

        $this->restore($config);

        return $held['layers'];
    }

    /**
     * Capture the central value of every root the layers touch, then write the layers over it, in pointer order.
     *
     * @param  array<string, array<string, ConfigOverrideEntry>>  $layers  root => pointer => entry
     */
    public function lay(Repository $config, array $layers): void
    {
        $central = [];
        $mutator = new JsonPathMutator;

        foreach ($layers as $root => $entries) {
            if ($entries === []) {
                continue;
            }

            $central[$root] = $config->get($root);
            $doc = is_array($central[$root]) ? $central[$root] : [];
            ksort($entries);

            foreach ($entries as $pointer => $entry) {
                $mutator->override($doc, JsonPointer::toJsonPath($pointer), $entry->valueOver($doc));
            }

            $config->set($root, $doc);
        }

        if ($central !== []) {
            $this->applied[$config] = ['central' => $central, 'layers' => $layers];
        }
    }

    protected function restore(Repository $config): void
    {
        $held = $this->applied[$config] ?? null;

        if ($held === null) {
            return;
        }

        foreach ($held['central'] as $root => $value) {
            $config->set($root, $value);
        }

        unset($this->applied[$config]);
    }

    /**
     * The tenant's published layers, without the entries its tier may no longer write (the key's declared ceiling, or
     * an operator lock) and without roots no section is registered for.
     *
     * @return array<string, array<string, ConfigOverrideEntry>>
     */
    protected function layers(): array
    {
        $store = $this->container->make(ConfigOverrideStore::class)->forTier(ConfigTier::Tenant);
        $sections = $this->container->make(ConfigSectionRegistry::class);
        $layers = [];

        foreach ($store->publishedLayers() as $root => $entries) {
            $registered = $sections->get($root);

            if ($registered === null || $entries === []) {
                continue;
            }

            // Read before the tenant layer is laid ({@see apply()} restores first), so this is the central value.
            $central = app('config')->get($root);
            $layers[$root] = self::restrict($registered, $entries, $store->locks($root), is_array($central) ? $central : null);
        }

        return array_filter($layers);
    }

    /**
     * The tenant entries the tenant tier may still apply NOW, at every depth (review-r1, 07: a lock must hold at any
     * depth). Shared by the runtime ({@see layers()}) and the tenant row, so the two cannot disagree:
     * - an entry is dropped when its own key's declared ceiling no longer admits the tenant tier, or an operator lock
     *   covers its pointer (a lock on the entry or on an ancestor of it);
     * - otherwise, inside an object value, each child the tenant may no longer write (a lock at or below it, or a
     *   declared ceiling that excludes the tenant tier) is CUT out at its HIGHEST such pointer and added to the entry's
     *   inherit list, so it takes the central effective value at runtime, or is absent where central has none, and the
     *   row reports it from the tier below;
     * - then every CONSTRAINT below the entry (a lock below its pointer, or a central key below it whose ceiling excludes
     *   the tenant) is walked along the entry's value ({@see constrain()}, review-r1 01:26Z): a null or scalar on the way
     *   cannot be kept in part, so it is cut whole at that node, and the entry is DROPPED when that node is the entry
     *   itself (the lock wins); an array on the way (empty included) is descended, and a missing key inherits only the
     *   constrained key. An empty array is thus kept as a map plus the locked key, as a map that omits a locked entry is.
     *
     * @param  array<string, ConfigOverrideEntry>  $entries  pointer => entry
     * @param  array<string, ConfigOverrideLock>  $locks
     * @param  array<array-key, mixed>|null  $central  the section's central effective value, which names the keys below a
     *                                                 non-object entry whose ceiling a package has since narrowed
     * @return array<string, ConfigOverrideEntry>
     */
    public static function restrict(RegisteredConfigSection $registered, array $entries, array $locks, ?array $central = null): array
    {
        $kept = [];

        foreach ($entries as $pointer => $entry) {
            $pointer = (string) $pointer;

            if (! ConfigTierBoundary::admits($registered, JsonPointer::segments($pointer), ConfigTier::Tenant, $locks)) {
                continue;
            }

            if (self::extendCollision($registered, $pointer, $entry, $central)) {
                continue;
            }

            $value = $entry->value;
            $cut = [];

            if (is_array($value) && $value !== []) {
                [$value, $cut] = ConfigTierBoundary::split($registered, $pointer, $value, ConfigTier::Tenant, $locks);
            }

            $walked = self::constrain($value, $cut, self::constraints($registered, $pointer, $value, $locks, $central));

            if ($walked === null) {
                continue;
            }

            [$value, $cut] = $walked;

            $kept[$pointer] = $cut === [] ? $entry : new ConfigOverrideEntry(
                $entry->pointer, $entry->op, $value, $entry->secret, $entry->revision, $entry->version, $entry->setAt,
                array_values(array_unique([...$entry->inherit, ...$cut])),
            );
        }

        return $kept;
    }

    /**
     * Whether an entry is an EXTEND entry the platform now also holds (config-sections ticket 09). `ConfigMerge::Extend`
     * lets the tenant tier ADD map entries and never replace the platform's: {@see ConfigOverrideStore::saveDraft()}
     * refuses a set on an entry the tier below already holds, but a platform entry added AFTER the tenant's row would
     * otherwise lose to it. So the same rule holds here, at apply (and in the row, which shares {@see restrict()}): a
     * `set` under an extend declaration whose pointer exists in the central effective value is not applied, and the
     * platform's entry wins, as the flagship's TenantModelCatalog merge did (`array_merge($tenant, $floor)`).
     *
     * @param  array<array-key, mixed>|null  $central
     */
    private static function extendCollision(RegisteredConfigSection $registered, string $pointer, ConfigOverrideEntry $entry, ?array $central): bool
    {
        if ($central === null || $entry->op !== ConfigOverrideOp::Set) {
            return false;
        }

        if ($registered->section::keyDeclaration(JsonPointer::segments($pointer))->merge !== ConfigMerge::Extend) {
            return false;
        }

        [$exists] = JsonPointer::get($central, $pointer);

        return $exists;
    }

    /**
     * The relative pointers below `$pointer` the tenant may not write, whatever the entry's value holds: every lock below
     * it (a lock holds its whole subtree, whatever a child declares), and the ceiling cuts {@see ConfigTierBoundary::split()}
     * makes over the UNION of the central value and the entry's value below it, so a key central holds and the entry
     * omits is still constrained, while a refused record with a tenant-owned child (nearest declaration, in either
     * side) is walked into, never cut whole (review-r1, 07: the same boundary the write side draws).
     *
     * @param  array<string, ConfigOverrideLock>  $locks
     * @param  array<array-key, mixed>|null  $central
     * @return list<string>
     */
    private static function constraints(RegisteredConfigSection $registered, string $pointer, mixed $value, array $locks, ?array $central): array
    {
        $relative = [];

        foreach ($locks as $lock) {
            if (str_starts_with($lock->pointer, $pointer.'/')) {
                $relative[] = substr($lock->pointer, strlen($pointer));
            }
        }

        [$exists, $below] = $central === null ? [false, null] : JsonPointer::get($central, $pointer);
        $union = self::union($exists ? $below : null, $value);

        if (is_array($union)) {
            array_push($relative, ...ConfigTierBoundary::split($registered, $pointer, $union, ConfigTier::Tenant)[1]);
        }

        return array_values(array_unique($relative));
    }

    /**
     * Both values' keys, recursively: an object on either side keeps its structure (a null or scalar on the other side
     * cannot hide the keys below it), and where both are objects their keys are merged.
     */
    private static function union(mixed $central, mixed $value): mixed
    {
        if (! is_array($central)) {
            return is_array($value) ? $value : $central;
        }

        if (! is_array($value)) {
            return $central;
        }

        foreach ($value as $key => $child) {
            $central[$key] = array_key_exists($key, $central) ? self::union($central[$key], $child) : $child;
        }

        return $central;
    }

    /**
     * Walk each constraint along `$value` (review-r1 01:26Z): at the constrained key, cut it; through an array (empty
     * included), descend; at a missing key, inherit only the constrained key; at a null or scalar, cut that whole node, or
     * return null (drop the entry) when the node is the entry's own value.
     *
     * @param  list<string>  $cut  relative pointers already cut
     * @param  list<string>  $constraints
     * @return array{0: mixed, 1: list<string>}|null
     */
    private static function constrain(mixed $value, array $cut, array $constraints): ?array
    {
        foreach ($constraints as $relative) {
            foreach ($cut as $already) {
                if ($relative === $already || str_starts_with($relative, $already.'/')) {
                    continue 2;
                }
            }

            $segments = JsonPointer::segments($relative);
            $node = $value;

            foreach ($segments as $i => $segment) {
                if (! is_array($node)) {
                    if ($i === 0) {
                        return null;
                    }

                    $at = JsonPointer::from(array_slice($segments, 0, $i));
                    self::forget($value, array_slice($segments, 0, $i));
                    $cut[] = $at;

                    continue 2;
                }

                if (! array_key_exists($segment, $node)) {
                    $cut[] = $relative;

                    continue 2;
                }

                $node = $node[$segment];
            }

            self::forget($value, $segments);
            $cut[] = $relative;
        }

        return [$value, $cut];
    }

    /**
     * @param  array<array-key, mixed>  $doc
     * @param  list<string>  $segments
     */
    private static function forget(array &$doc, array $segments): void
    {
        $key = array_shift($segments);

        if ($key === null || ! array_key_exists($key, $doc)) {
            return;
        }

        if ($segments === []) {
            unset($doc[$key]);
        } elseif (is_array($doc[$key])) {
            self::forget($doc[$key], $segments);
        }
    }

    protected function tenancyActive(): bool
    {
        try {
            return $this->container->make(TenancyContext::class)->isActive();
        } catch (Throwable) {
            return false;
        }
    }
}
