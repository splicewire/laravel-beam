<?php

namespace Splicewire\Beam\Localization\Concerns;

use LogicException;

/**
 * Who registered a declaration, stamped ONCE at registration (localization 11; coord-lead ruling 22:06Z on
 * SIGNATURES-11). The text a declaration carries is keyed in its registrant's namespace, so the registrant must not be
 * silently rewritten: a later stamp with a DIFFERENT registrant is ignored and reported; the first one stands.
 */
trait HasSetOnceRegistrant
{
    private ?string $registrant = null;

    private bool $registrantStamped = false;

    /** The registrant (a `by:` composer name, a class, or null for the host). */
    public function registrant(): ?string
    {
        return $this->registrant;
    }

    /** Stamp the registrant; set-once: the first stamp stands, a different later one is ignored and reported. */
    public function stampRegistrant(?string $by): void
    {
        if (! $this->registrantStamped) {
            $this->registrant = $by;
            $this->registrantStamped = true;

            return;
        }
        if ($by !== $this->registrant) {
            report(new LogicException(sprintf(
                '%s is already registered by [%s]; a second registration by [%s] does not rewrite its registrant (its text stays keyed in the first namespace).',
                static::class, $this->registrant ?? 'host', $by ?? 'host',
            )));
        }
    }
}
