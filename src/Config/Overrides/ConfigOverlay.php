<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Schemastud\DataSchemas\Overlay\JsonPathMutator;
use Splicewire\Beam\Config\JsonPointer;
use Throwable;

/**
 * Applies the operator tier's PUBLISHED overrides to the running configuration (ADR-0245 decision 2 and §9;
 * config-sections ticket 05; PRD §Overlay application).
 *
 * - It never holds a config repository: every read and write resolves `app('config')` at the call, so a host that
 *   rebinds the repository (Octane, a test) is always the one written.
 * - It snapshots each root it touches before the first write and restores those roots exactly before it re-applies or
 *   falls back, because a long-lived queue worker has no Octane sandbox to throw a request's config away.
 * - Each `set` / explicit `null` is applied in pointer order through DataOverlay's `override` (an upsert at a concrete
 *   path, {@see JsonPointer::toJsonPath()}); an `unset` is the absence of an entry and is never sent to DataOverlay.
 * - {@see sync()} is what the three hooks call. It re-applies only when the published-version counter has moved since
 *   it last applied. Nothing it meets is thrown (root ruling df42bf60, 2026-11-15, amending PRD:224):
 *   - a counter CACHE that cannot be read skips the cache, not the tier: the published layer is read uncached on every
 *     call until the cache answers again, so the operator tier keeps applying (a published `review_required` stays
 *     required). One warning is logged per failure streak;
 *   - an override STORE that cannot be read (or cannot be built) restores the base configuration and applies nothing:
 *     config falls back to the package and host tiers, and the failure is logged.
 * - The store is resolved inside that guard, never at construction, so a boot without the store's bindings falls back
 *   instead of failing.
 */
class ConfigOverlay
{
    /** @var array<string, mixed> root => its value before this overlay first wrote it */
    protected array $base = [];

    /** @var array<string, array<string, ConfigOverrideEntry>> root => pointer => the published entry applied */
    protected array $applied = [];

    protected ?int $appliedAt = null;

    /** Whether the current counter-cache failure streak has been logged. */
    protected bool $uncachedWarned = false;

    public function __construct(
        protected Container $container,
        protected ConfigOverrideCounter $counter,
    ) {}

    /**
     * Re-apply when the counter moved, or on every call while the counter cache cannot be read; fall back to the base
     * configuration only when the override store cannot be read.
     */
    public function sync(): void
    {
        try {
            $counter = $this->counter->current();
            $this->uncachedWarned = false;
        } catch (Throwable $e) {
            $counter = null;

            if (! $this->uncachedWarned) {
                $this->uncachedWarned = true;
                Log::warning('[beam] the config override counter cache could not be read; reading the published overrides uncached until it answers.', [
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($counter !== null && $this->appliedAt === $counter) {
            return;
        }

        try {
            $layers = $this->container->make(ConfigOverrideStore::class)->publishedLayers();
        } catch (Throwable $e) {
            $this->fallBack('the override store could not be read', $e);

            return;
        }

        $this->restore();
        $this->apply($layers);
        // Uncached (null): nothing to compare against, so the next call reads the published layer again.
        $this->appliedAt = $counter;
    }

    /**
     * Apply published layers over the current configuration, snapshotting each root first.
     *
     * @param  array<string, array<string, ConfigOverrideEntry>>  $layers  root => pointer => entry
     */
    public function apply(array $layers): void
    {
        $config = app('config');
        $mutator = new JsonPathMutator;

        foreach ($layers as $root => $entries) {
            if ($entries === []) {
                continue;
            }

            if (! array_key_exists($root, $this->base)) {
                $this->base[$root] = $config->get($root);
            }

            $doc = $config->get($root);
            $doc = is_array($doc) ? $doc : [];
            ksort($entries);

            foreach ($entries as $pointer => $entry) {
                $mutator->override($doc, JsonPointer::toJsonPath($pointer), $entry->value);
            }

            $config->set($root, $doc);
            $this->applied[$root] = $entries;
        }
    }

    /** Put every root this overlay wrote back exactly as it was, and forget what was applied. */
    public function restore(): void
    {
        $config = app('config');

        foreach ($this->base as $root => $value) {
            $config->set($root, $value);
        }

        $this->base = [];
        $this->applied = [];
        $this->appliedAt = null;
    }

    /** A root's value without this overlay: the package and host tiers (and any provider write) only. */
    public function base(string $root): mixed
    {
        return array_key_exists($root, $this->base) ? $this->base[$root] : app('config')->get($root);
    }

    /** @return array<string, ConfigOverrideEntry> the published entries applied to a root, pointer => entry */
    public function appliedTo(string $root): array
    {
        return $this->applied[$root] ?? [];
    }

    /**
     * Whether this process is caching the configuration (`config:cache`, or `optimize`, which runs it). Laravel builds
     * a fresh application for the cache and boots it, so the booted hook would otherwise apply the operator tier inside
     * it; the cached file must hold the package and host tiers only, with the overlay applied on top at runtime.
     */
    public static function cachesConfiguration(): bool
    {
        $command = $_SERVER['argv'][1] ?? null;

        return app()->runningInConsole() && in_array($command, ['config:cache', 'optimize'], true);
    }

    protected function fallBack(string $why, Throwable $e): void
    {
        $this->restore();
        Log::warning("[beam] config overrides not applied: {$why}; config falls back to the package and host tiers.", [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
