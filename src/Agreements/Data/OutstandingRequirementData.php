<?php

namespace Splicewire\Beam\Agreements\Data;

use InvalidArgumentException;
use Spatie\LaravelData\Data;

/** The caller-actionable facts for one unmet Requirement, with no assent or response behavior attached. */
class OutstandingRequirementData extends Data
{
    public function __construct(
        public string $key,
        public int $version,
        public string $digest,
        public string $kind,
        public string $title,
        public string $scope,
        public string|int|null $subject,
        public string $readUrl,
        public ?string $acceptOperation,
        public ?string $member,
    ) {
        if (($acceptOperation === null) === ($member === null)) {
            throw new InvalidArgumentException('An outstanding requirement must declare exactly one remedy: an accept operation or an input member.');
        }
    }
}
