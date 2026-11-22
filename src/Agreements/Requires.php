<?php

namespace Splicewire\Beam\Agreements;

use InvalidArgumentException;
use ReflectionClass;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataContainer;

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
    public const SCOPES = ['party', 'organisation', 'subject', 'occurrence'];

    public const ON = ['read', 'write', 'both', 'create'];

    /**
     * @param  string  $agreement  the Agreement key, e.g. `terms`
     * @param  'party'|'organisation'|'subject'|'occurrence'  $scope  who must have assented: the acting party, its organisation,
     *                                                                the party for this one subject, or a member on this request
     * @param  'read'|'write'|'both'|'create'  $on  which resource verbs it binds — `read` (list + detail), `write`
     *                                              (create, update, delete), both, or `create` (agreements 16: only a
     *                                              write that makes a NEW record; update and delete of an existing one
     *                                              are not bound). Meaningful only on a resource; an operation is one
     *                                              behavior and ignores `read|write|both`, and refuses `create`.
     *
     * ⚠️ `on: 'create'` is declared under the resource's SAME write behavior (`{key}:write`), so every reader of that
     * behavior (the ledger's `behavior` column, a 428's behavior, an attachment on `{key}:write`, the member-schema
     * projection) is unchanged; only ADMISSION tells a new record from an existing one, by its subject
     * ({@see appliesToSubject()}). The limit that follows: a resource has ONE write schema for create and update, so
     * on an editable resource whose edit input carries the AgreementMemberData field, the member renders on the edit
     * form too, where it is not required.
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
        return $this->on === 'read' || $this->on === 'both';
    }

    public function bindsWrite(): bool
    {
        return $this->on !== 'read';
    }

    /**
     * agreements 16: whether this Requirement binds ONLY a write that makes a new record (`on: 'create'`), so update and
     * delete of an existing record are not bound.
     */
    public function bindsOnlyNewRecords(): bool
    {
        return $this->on === 'create';
    }

    /**
     * agreements 16: whether this Requirement applies to a behavior attempted on `$subject`. A create carries no subject
     * (the record has no identity yet); an update or delete carries the existing record. A create-only Requirement does
     * not apply to an existing record; every other Requirement applies whatever the subject. Every resolver (beam's
     * default, an agreements seam's) asks this before treating a Requirement as outstanding.
     */
    public function appliesToSubject(mixed $subject): bool
    {
        return ! ($this->bindsOnlyNewRecords() && $subject !== null);
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

    /** Whether an input Data class declares an AgreementMemberData field. */
    public static function inputDeclaresMember(string|false|null $input): bool
    {
        if (! is_string($input) || ! is_subclass_of($input, Data::class)) {
            return false;
        }

        $dataClass = DataContainer::get()->dataClassFactory()->build(new ReflectionClass($input));

        foreach ($dataClass->properties as $property) {
            $member = $property->type->dataClass;

            if (is_string($member) && is_a($member, \Splicewire\Beam\Agreements\Data\AgreementMemberData::class, true)) {
                return true;
            }
        }

        return false;
    }

    /** Refuse a Requirement that can only be met by a member when its input cannot carry one. */
    public static function assertMemberInput(?array $requires, string|false|null $input, string $owner, bool $createsSubject = false): void
    {
        foreach ($requires ?? [] as $requirement) {
            if ($requirement->scope !== 'occurrence' && ! ($createsSubject && $requirement->scope === 'subject')) {
                continue;
            }

            if (! self::inputDeclaresMember($input)) {
                throw new InvalidArgumentException(sprintf(
                    '%s requires [%s] at scope [%s], but its input declares no AgreementMemberData field.',
                    $owner, $requirement->agreement, $requirement->scope,
                ));
            }
        }
    }
}
