<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Schemastud\DataSchemas\Overlay\JsonPathMutator;
use Splicewire\Beam\Config\JsonPointer;
use Throwable;
use WeakMap;

/**
 * Applies the operator tier's PUBLISHED overrides to the running configuration (ADR-0245 decision 2 and §9;
 * config-sections ticket 05; PRD §Overlay application).
 *
 * **Guarantee.** With a working cache, a publish reaches the very next request or job. When a cache WRITE fails, a
 * long-lived process (Octane, a queue worker) picks it up within `beam.core.config_overrides.recheck_seconds`, and the
 * publish response and the log say so ({@see ConfigOverrideStore::cacheWriteFailedNotice()}); `recheck_seconds = 0`
 * re-derives the token from the store on every sync, which keeps the next-request guarantee through a failed write
 * (root ruling (A), row 64e24681, 2026-11-15: the bounded fault-mode delay is accepted when it is surfaced and
 * tunable to strict). When the cache cannot be READ, the published overrides are read uncached on every call (root ruling df42bf60, amending
 * PRD:224). Only an override store that cannot be read (or built) falls back to the package and host tiers. Nothing
 * here throws, and boot never fails. The publishing process applies its own publish at once
 * ({@see ConfigOverrideStore::publish()}).
 *
 * **Every repository it meets gets the published layer.** Under Octane each operation runs on a fresh CLONE of the
 * worker's repository (`CreateConfigurationSandbox`) while this singleton lives on (review-r1, 2026-11-15), so what was
 * applied is tracked per repository in a WeakMap keyed by the repository object. Weak identity keys hold no values and
 * write nothing through a remembered repository, which is HANDBACK-03's rule for the ladder hooks (resolve `app('config')`
 * on every call, keep only values). The singleton keeps only repository-independent values:
 * - the GLOBAL package+host base of each root, captured the first time the overlay touches it, before anything is
 *   applied (worker or process boot). Applying writes each touched root's base back into the current repository,
 *   then the layers, so a key unset after boot reverts to its host value in every clone;
 * - the published layers memoised by token, so a new clone costs array writes, not a store read.
 * A host's own `config()->set` on a touched root after the operation's sync stands until the next operation's sync.
 *
 * **Ordering (handoff to ticket 07, tenant tier).** The operator sync must run BEFORE tenancy initialises in an
 * operation; the tenant tier applies on top of it. Requests satisfy this (the middleware is prepended global
 * middleware). The JobProcessing listener shares its event with tenancy's queue bootstrapper; 07 owns that order.
 *
 * Each `set` / explicit `null` is applied in pointer order through DataOverlay's `override` (an upsert at a concrete
 * path, {@see JsonPointer::toJsonPath()}); an `unset` is the absence of an entry and is never sent to DataOverlay.
 */
class ConfigOverlay
{
    /** @var array<string, mixed> root => its package+host value, captured before the overlay first wrote it */
    protected array $base = [];

    /** @var WeakMap<Repository, string> repository => the token whose layers it holds */
    protected WeakMap $applied;

    /** @var array<string, array<string, ConfigOverrideEntry>> the published layers read for `layersToken` */
    protected array $layers = [];

    protected ?string $layersToken = null;

    /** When this process last re-derived the token from the store (ms); null forces it on the next sync. */
    protected ?int $checkedAt;

    /**
     * After a failed cache write, the stale token the cache still holds and the store's real token, so the stale value
     * cannot take a repository back until the cache is repaired or says something else.
     *
     * @var array{0: string, 1: string}|null
     */
    protected ?array $correction = null;

    /** Whether the current counter-cache read-failure streak has been logged. */
    protected bool $uncachedWarned = false;

    public function __construct(
        protected Container $container,
        protected ConfigOverrideCounter $counter,
    ) {
        $this->applied = new WeakMap;
        // A process starts as "just checked": an FPM request is short-lived and reads the layers fresh anyway; a
        // long-lived process re-derives from the store once the bound passes.
        $this->checkedAt = $this->nowMs();
    }

    /** Bring the CURRENT repository (`app('config')`) to the published state. Never throws. */
    public function sync(): void
    {
        $config = app('config');

        try {
            $token = $this->counter->current();
            $this->uncachedWarned = false;
        } catch (Throwable $e) {
            $this->warnUncached($e);
            $this->syncUncached($config);

            return;
        }

        try {
            $token = $this->resolveToken($token);
        } catch (Throwable $e) {
            $this->fallBack('the override store could not be read', $e);

            return;
        }

        if (isset($this->applied[$config]) && $this->applied[$config] === $token) {
            return;
        }

        if ($this->layersToken !== $token) {
            try {
                $this->layers = $this->store()->publishedLayers();
                $this->layersToken = $token;
            } catch (Throwable $e) {
                $this->fallBack('the override store could not be read', $e);

                return;
            }
        }

        $this->applyTo($config, $this->layers);
        $this->applied[$config] = $token;
    }

    /** Force the next sync to re-derive the token from the store (after a publish in this process). */
    public function recheckNow(): void
    {
        $this->checkedAt = null;
    }

    /** A root's value without this overlay: the package and host tiers (and any provider write) only. */
    public function base(string $root): mixed
    {
        return array_key_exists($root, $this->base) ? $this->base[$root] : app('config')->get($root);
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

    /**
     * The token to trust: the cached one, reseeded from the store when absent, re-derived from the store at most once
     * per `recheck_seconds` (a failed write leaves the cache stale), and corrected while the cache still says the
     * stale value it said at the last recheck.
     */
    protected function resolveToken(?string $cached): string
    {
        if ($cached === null) {
            $token = $this->store()->publishedToken();
            $this->checkedAt = $this->nowMs();
            $this->tryPut($token);

            return $token;
        }

        if ($this->recheckDue()) {
            $fromStore = $this->store()->publishedToken();
            $this->checkedAt = $this->nowMs();
            $this->correction = $fromStore === $cached ? null : [$cached, $fromStore];

            if ($this->correction !== null) {
                $this->tryPut($fromStore);
            }
        }

        return $this->correction !== null && $this->correction[0] === $cached ? $this->correction[1] : $cached;
    }

    protected function recheckDue(): bool
    {
        if ($this->checkedAt === null) {
            return true;
        }

        // 0 is strict: every sync re-derives the token (root ruling (A), row 64e24681).
        return $this->nowMs() - $this->checkedAt >= self::recheckSeconds() * 1000;
    }

    /** `beam.core.config_overrides.recheck_seconds`, never negative. */
    public static function recheckSeconds(): int
    {
        return max(0, (int) config('beam.core.config_overrides.recheck_seconds', 30));
    }

    /** The counter cache cannot be read: the published layer is read and applied on every call (df42bf60). */
    protected function syncUncached(Repository $config): void
    {
        try {
            $layers = $this->store()->publishedLayers();
        } catch (Throwable $e) {
            $this->fallBack('the override store could not be read', $e);

            return;
        }

        $this->applyTo($config, $layers);
        unset($this->applied[$config]);
    }

    /**
     * Write every touched root's package+host base into `$config`, then apply the layers. A root seen for the first
     * time has its base captured from `$config` before anything is written to it.
     *
     * @param  array<string, array<string, ConfigOverrideEntry>>  $layers  root => pointer => entry
     */
    protected function applyTo(Repository $config, array $layers): void
    {
        foreach ($layers as $root => $entries) {
            if ($entries !== [] && ! array_key_exists($root, $this->base)) {
                $this->base[$root] = $config->get($root);
            }
        }

        foreach ($this->base as $root => $value) {
            $config->set($root, $value);
        }

        $mutator = new JsonPathMutator;

        foreach ($layers as $root => $entries) {
            if ($entries === []) {
                continue;
            }

            $doc = $config->get($root);
            $doc = is_array($doc) ? $doc : [];
            ksort($entries);

            foreach ($entries as $pointer => $entry) {
                $mutator->override($doc, JsonPointer::toJsonPath($pointer), $entry->valueOver($doc));
            }

            $config->set($root, $doc);
        }
    }

    protected function store(): ConfigOverrideStore
    {
        return $this->container->make(ConfigOverrideStore::class);
    }

    protected function tryPut(string $token): void
    {
        try {
            $this->counter->put($token);
        } catch (Throwable) {
            // The cache is a notification; the store is the truth, and the next recheck repeats this.
        }
    }

    protected function nowMs(): int
    {
        return (int) now()->getTimestampMs();
    }

    protected function warnUncached(Throwable $e): void
    {
        if ($this->uncachedWarned) {
            return;
        }

        $this->uncachedWarned = true;
        Log::warning('[beam] the config override counter cache could not be read; reading the published overrides uncached until it answers.', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    /** The store cannot be read: the current repository gets the package and host tiers, and the failure is logged. */
    protected function fallBack(string $why, Throwable $e): void
    {
        $config = app('config');

        foreach ($this->base as $root => $value) {
            $config->set($root, $value);
        }

        unset($this->applied[$config]);
        $this->layersToken = null;
        Log::warning("[beam] config overrides not applied: {$why}; config falls back to the package and host tiers.", [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
