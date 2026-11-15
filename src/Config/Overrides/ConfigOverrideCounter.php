<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use RuntimeException;

/**
 * The ONE published-state token the operator tier's three hooks key on (config-sections ticket 05; PRD §Overlay
 * application). It lives in the cache store `beam.core.config_overrides.cache_store` names (null: the host's default
 * store), which every process must share and which must not be tenant-tagged.
 *
 * The token is DERIVED FROM THE STORE ({@see ConfigOverrideStore::publishedToken()}), a fingerprint of every section's
 * published pin, never a count (review-r1, 2026-11-15). A publish PUTs the new fingerprint; an absent key is reseeded
 * from the store. A flushed cache therefore never reuses a token a warm process already applied, and the cache is only
 * a notification: a write that fails is caught up by the overlay's bounded recheck ({@see ConfigOverlay::sync()}).
 * Readers compare tokens for equality only.
 *
 * Both methods throw when the cache cannot be used; the callers decide what that means (the overlay reads the published
 * layer uncached, root ruling df42bf60; a publish still commits and warns).
 */
class ConfigOverrideCounter
{
    public const KEY = 'beam:config-overrides:published';

    public function __construct(protected CacheFactory $cache) {}

    /** The cached token, or null when the key is absent (cold, flushed or evicted). Throws when the store cannot be read. */
    public function current(): ?string
    {
        $token = $this->store()->get(self::KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Announce the published state so every process re-reads on its next request, job or command. Throws on failure,
     * including a write the store refuses by returning false (Laravel's `Repository::forever()` and stores such as
     * Memcached report a failed write that way, not by throwing; review-r1, 2026-11-15).
     */
    public function put(string $token): void
    {
        if ($this->store()->forever(self::KEY, $token) === false) {
            throw new RuntimeException('The config override cache store refused the write.');
        }
    }

    protected function store(): Repository
    {
        return $this->cache->store(config('beam.core.config_overrides.cache_store'));
    }
}
