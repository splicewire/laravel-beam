<?php

namespace Splicewire\Beam\Events;

use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Splicewire\Beam\Models\Hook;

/**
 * The `hooks` resource's own two event types (api-surface-coherence ticket 38, decided by 12 §8).
 *
 * ## A hook that reports its own health is a loop, and it is the right loop
 *
 * `hooks.disabled` fires when a subscription auto-disables after `consecutive_failures`, and it is
 * subscribable like any other event — which sounds circular and is not, because the hook that
 * reports the failure is a DIFFERENT subscription than the one that failed. What it must not be is
 * the ONLY channel: an operator whose single hook endpoint went down would be told about it through
 * the endpoint that went down. 12 §8 is explicit that hook health also reaches a person through the
 * notification subscriber, and that requirement lives with the notification wiring rather than here
 * — this registrar's job is only to make the names exist in the catalog.
 *
 * ## Why imperative, and not `#[BeamEvent]` on an event class
 *
 * There is no `HookDisabled` event class to hang the attribute on: the transition is recorded by
 * {@see Hook::recordFailure()} returning true, inside a queue job. The attribute is sugar over
 * `register()` ({@see BeamEvent}'s own docblock says so), and inventing an event class purely to
 * carry a declaration would be the tail wagging the dog. When tickets 27/31 author the payload
 * shapes, the declaration moves onto them.
 *
 * Registered from beam CORE, not from a host, because `hooks` is a beam-core resource — the prefix
 * resolves on every host that has the resource, and on one that does not it is an ADVISORY finding
 * from `EventCatalogPrefixAudit` rather than a boot failure (ticket 91).
 */
class HookEventRegistrar implements Registrar
{
    public function fill(Registry $registry): void
    {
        foreach ($this->types() as $type) {
            $registry->register($type, null, self::class);
        }
    }

    /** @return list<EventType> */
    public function types(): array
    {
        return [
            new EventType(
                name: 'hooks.disabled',
                subject: Hook::class,
                description: 'A hook auto-disabled after consecutive delivery failures; op/reset is the way back.',
            ),
            new EventType(
                name: 'hooks.verified',
                subject: Hook::class,
                description: 'A hook answered the hooks.ping round trip with a 2xx and is now verified.',
            ),
        ];
    }

    public function source(): string
    {
        return 'beam-core hook lifecycle events';
    }
}
