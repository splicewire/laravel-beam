<?php

namespace Splicewire\Beam\Schema\Contracts;

/** Optional request-cache capability. Null means that this registry must be read fresh. */
interface SchemaRegistryCacheVersion
{
    public function cacheVersion(): ?string;
}
