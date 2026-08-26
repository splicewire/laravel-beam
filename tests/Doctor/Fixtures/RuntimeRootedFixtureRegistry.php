<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryArity;
use Rushing\Popcorn\Registries\RegistryIndex;

/**
 * A registry that declares itself at RUNTIME: no `#[IsRegistry]` anywhere on the class, the declaration
 * handed to {@see BasicRegistry::__construct()} as a value with a root computed by the caller
 * (registry-kernel 26 D2).
 *
 * This is the shape the conformance gate's index branch used to filter out before any check ran — neither a
 * container binding nor an attribute-carrying owner, so a static attribute read saw nothing and concluded
 * the registry was not one. It is deliberately not bound into the container by the tests that use it: the
 * index is the ONLY thing that can see it, which is the whole point.
 */
class RuntimeRootedFixtureRegistry implements Registry
{
    use ForwardsToBasicRegistry;

    public function __construct(string $root, bool $writeOnDuplicate = true)
    {
        // `onDuplicate` defaults, and an INSTANCE cannot say whether the author wrote `Supersede` or
        // inherited it. Both spellings are constructed here so the gate can be shown treating the argument
        // as unanswerable rather than guessing — a guess would fail the registry on the second branch.
        $this->entries = new BasicRegistry($writeOnDuplicate
            ? new IsRegistry(
                root: $root,
                of: 'a registry whose root was computed at boot',
                arity: RegistryArity::PickOne,
                onDuplicate: OnDuplicate::Supersede,
            )
            : new IsRegistry(
                root: $root,
                of: 'a registry whose root was computed at boot',
                arity: RegistryArity::PickOne,
            ));
    }

    public function describeInto(RegistryIndex $index): static
    {
        $index->describe($this->entries, $this);

        return $this;
    }
}
