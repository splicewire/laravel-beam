<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;

/**
 * The ONE published-version counter the operator tier's three hooks key on (config-sections ticket 05; PRD §Overlay
 * application). A publish moves it; a request, a job or a command that sees a value other than the one it applied
 * re-reads the published overrides. It lives in the cache store `beam.core.config_overrides.cache_store` names (null:
 * the host's default store), which every process must share and which must not be tenant-tagged.
 *
 * An absent key reads as 0, so a fresh or flushed cache still applies what is published. A store that throws is
 * reported to the caller, which then falls back to the package and host tiers rather than failing boot.
 */
class ConfigOverrideCounter
{
    public const KEY = 'beam:config-overrides:published';

    public function __construct(protected CacheFactory $cache) {}

    /** The current counter; 0 when the key is absent. Throws when the store cannot be read. */
    public function current(): int
    {
        return (int) $this->store()->get(self::KEY, 0);
    }

    /** Move the counter so every process re-applies on its next request, job or command. */
    public function bump(): int
    {
        $store = $this->store();

        if (! $store->has(self::KEY)) {
            $store->forever(self::KEY, 0);
        }

        return (int) $store->increment(self::KEY);
    }

    protected function store(): Repository
    {
        return $this->cache->store(config('beam.core.config_overrides.cache_store'));
    }
}
