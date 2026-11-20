<?php

namespace Splicewire\Beam\Agreements;

use InvalidArgumentException;

/**
 * One Requirement a behavior's own declaration attaches (agreements 05, ADR-0247 amendment): the Agreement
 * key a caller must have assented to, at which scope, and — on a resource — which verbs it binds.
 *
 * It is the value of the `requires:` slot on `#[ParticleOp]` / {@see \Splicewire\Beam\Particle\ParticleOperation}
 * and `#[ParticleResource]` / {@see \Splicewire\Beam\Particle\ParticleResource}, so it must be spellable as an
 * attribute argument: `requires: [new Requires('terms', scope: 'party', on: 'both')]`.
 *
 * It names a key, never an Agreement class: beam does not know agreements. A host without an agreements seam
 * refuses every caller of a slot-declared behavior as an unknown (`unregistered`) Agreement — see
 * {@see SlotRequirementResolver}.
 *
 * @api
 */
final class Requires
{
    public const SCOPES = ['party', 'organisation', 'subject'];

    public const ON = ['read', 'write', 'both'];

    /**
     * @param  string  $agreement  the Agreement key, e.g. `terms`
     * @param  'party'|'organisation'|'subject'  $scope  who must have assented: the acting party, its organisation, or
     *                                                   the party for this one subject
     * @param  'read'|'write'|'both'  $on  which resource verbs it binds — `read` (list + detail), `write` (create,
     *                                     update, delete) or both. Meaningful only on a resource; an operation is
     *                                     one behavior and ignores it
     */
    public function __construct(
        public readonly string $agreement,
        public readonly string $scope = 'party',
        public readonly string $on = 'both',
    ) {
        if (trim($agreement) === '') {
            throw new InvalidArgumentException('A Requires names a non-empty Agreement key.');
        }

        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Requires [%s] declares scope [%s]; it must be one of %s.', $agreement, $scope, implode(', ', self::SCOPES),
            ));
        }

        if (! in_array($on, self::ON, true)) {
            throw new InvalidArgumentException(sprintf(
                'Requires [%s] declares on [%s]; it must be one of %s.', $agreement, $on, implode(', ', self::ON),
            ));
        }
    }

    public function bindsRead(): bool
    {
        return $this->on !== 'write';
    }

    public function bindsWrite(): bool
    {
        return $this->on !== 'read';
    }

    /**
     * Assert a declared `requires:` slot is a list of Requires; null (undeclared) passes.
     *
     * @param  array<mixed>|null  $requires
     */
    public static function assertList(?array $requires, string $owner): void
    {
        if ($requires === null) {
            return;
        }

        foreach ($requires as $requirement) {
            if (! $requirement instanceof self) {
                throw new InvalidArgumentException(sprintf(
                    '%s declares `requires:` with a [%s]; every entry must be a %s.',
                    $owner, get_debug_type($requirement), self::class,
                ));
            }
        }
    }
}
