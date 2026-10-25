<?php

namespace Splicewire\Beam\Schema;

/** Container-scoped registration revision; never shared across requests or queue jobs. */
final class SchemaRegistryRevision
{
    private string $scope;

    private int $revision = 0;

    public function __construct()
    {
        $this->scope = bin2hex(random_bytes(16));
    }

    public function changed(): void
    {
        $this->revision++;
    }

    public function version(): string
    {
        return $this->scope.':'.$this->revision;
    }
}
