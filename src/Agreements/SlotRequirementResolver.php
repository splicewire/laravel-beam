<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;

/**
 * Beam's default {@see RequirementResolver} (agreements 05): no agreements seam is installed, so beam cannot know any
 * Agreement a `requires:` slot names. Every slot Requirement of the attempted behavior is therefore OUTSTANDING, as an
 * unknown Agreement: `kind: 'unregistered'`, the key as its title, version 0, no digest, no read URL and no remedy.
 * A slot-carrying declaration refuses everyone at 428 until an agreements adapter binds its own resolver;
 * {@see \Splicewire\Beam\Doctor\UnseamedRequirementSlotAudit} names each such behavior.
 *
 * With no slot anywhere it reports nothing, exactly as {@see NullRequirementResolver}.
 *
 * @api
 */
class SlotRequirementResolver implements RequirementResolver
{
    public const UNREGISTERED = 'unregistered';

    public function __construct(protected SlotRequirementDeclarations $slots) {}

    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        return array_map(
            fn (Requires $requirement): OutstandingRequirementData => new OutstandingRequirementData(
                key: $requirement->agreement,
                version: 0,
                digest: '',
                kind: self::UNREGISTERED,
                title: $requirement->agreement,
                scope: $requirement->scope,
                subject: $requirement->scope === 'subject' && $subject instanceof Model ? $subject->getKey() : null,
                readUrl: '',
                acceptOperation: null,
                member: null,
            ),
            // agreements 16: a create-only Requirement is not outstanding for an existing record (update, delete).
            array_values(array_filter(
                $this->slots->requirementsFor($behavior),
                fn (Requires $requirement): bool => $requirement->appliesToSubject($subject),
            )),
        );
    }
}
