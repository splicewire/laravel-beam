<?php

namespace Splicewire\Beam\Tests\Fixtures;

/**
 * A generator that accepts NOTHING — the case that turns a hardcoded generator into a crash.
 *
 * `ChainedGenerator::generate()` THROWS when no configured generator accepts a class, where a
 * bare `new JsonSchemaGenerator` would have tried anyway. A consumer that resolves the chain
 * without asking `canGenerate()` first therefore converts an advisory audit into a boot-time
 * fatal at exactly the hosts whose `generators` list differs from the flagship's.
 */
class RefusingFixtureGenerator extends NarrowFixtureGenerator
{
    public function __construct(array $config = [])
    {
        parent::__construct($config, accepts: '');
    }
}
