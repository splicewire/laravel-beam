<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Events\EventTypeRegistry;

/**
 * The advisory that used to be a boot-fatal throw (api-surface-coherence ticket 91).
 *
 * An event name is `{resourceKey}.{verbPhrase}`, and a name whose resource key is not a live resource on
 * this host is a subscribable name pointed at nothing — a subscriber can store it and will never be
 * delivered anything. That is worth reporting. It is not worth refusing to boot over, and beam spent a
 * night proving the difference: `EventTypeRegistry` threw on this at registration, `~/Herd/tower`
 * declares `compositions.generate.*` without registering a `compositions` particle resource, and every
 * `artisan` invocation on that host — including `--version` — died inside a service provider's
 * `booted()` callback.
 *
 * ## Why an advisory rather than a deferred throw
 *
 * Deferring the check to `Application::booted()` is the fix for an ORDERING bug, and this was not one:
 * tower's declaration was already inside `$this->app->booted()` when it threw. The resource is absent
 * from that host at every point in its lifecycle, so there is no later moment at which the same throw
 * becomes correct. The check does not need a better moment; it needs to stop being fatal.
 *
 * ## Why it is not a gate registration either
 *
 * The same reason, one tier down. A gate finding fails `splicewire:beam:doctor`'s exit code, and a host
 * that legitimately declares an arm's events ahead of mounting that arm's resources would have a red
 * doctor with nothing broken. 62 of this estate's 64 audits are advisory; this is the 63rd, and the
 * finding names every offending event so the repair is a work-list rather than a hunt.
 *
 * ## What it cannot see
 *
 * Only what is registered in the running host. A host that never declares an event type gets a Pass
 * saying so, which is honest: an empty catalog has no dead prefixes.
 */
class EventCatalogPrefixAudit implements DoctorAudit
{
    public const CHECK = 'events.catalog-prefix';

    public function __construct(private EventTypeRegistry $events) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $total = count($this->events->all());

        if ($total === 0) {
            return [Finding::pass(self::CHECK, 'No event types are registered on this host — an empty catalog has no dead prefixes.')];
        }

        $unresolved = $this->events->unresolvedPrefixes();

        if ($unresolved === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d event type%s registered; every resource-key prefix resolves to a live resource.',
                $total,
                $total === 1 ? '' : 's',
            ))];
        }

        $rows = [];

        foreach ($unresolved as $name => $resourceKey) {
            $rows[] = sprintf('%s (prefix [%s])', $name, $resourceKey);
        }

        $known = $this->events->knownResourceKeys();

        return [Finding::warn(self::CHECK, sprintf(
            '%d of %d registered event type%s hang%s off a resource key that is not registered anywhere, so a '
                .'subscription to %s would never be delivered: %s. Either register the resource on this host or drop '
                .'the declaration. Live resource keys: %s.',
            count($unresolved),
            $total,
            $total === 1 ? '' : 's',
            count($unresolved) === 1 ? 's' : '',
            count($unresolved) === 1 ? 'it' : 'them',
            implode(', ', $rows),
            $known === [] ? '(none — no resource registry is populated)' : implode(', ', $known),
        ))];
    }
}
