<?php

namespace Splicewire\Beam\Tests\Schema\Fixtures;

use RuntimeException;
use Rushing\Popcorn\Binding;
use Schemastud\DataSchemas\Migration\Contracts\LlmMigrator;

/**
 * A deterministic stand-in for a host's model-backed {@see LlmMigrator}, built by
 * the record-versioning seam tests' injected LLM-migrator factory. It returns a
 * fixed candidate (which the LlmTryRung then runs through the SAME acceptance gate),
 * or — with `$failIfCalled` — throws to PROVE the rung never invoked it when a gate
 * is closed. No real model, so the eager drain stays deterministic.
 */
class FixtureLlmMigrator implements LlmMigrator
{
    /**
     * @param  array<string, mixed>|null  $candidate  the fixed migrated candidate to return
     */
    public function __construct(
        private ?array $candidate = null,
        private bool $failIfCalled = false,
    ) {}

    public function name(): string
    {
        return 'test.record-versioning.llm-migrator';
    }

    public function binding(): Binding
    {
        return Binding::Local;
    }

    public function invoke(array $input): array
    {
        if ($this->failIfCalled) {
            throw new RuntimeException('The LLM migrator must not be invoked when a gate is closed.');
        }

        return $this->candidate ?? [];
    }
}
