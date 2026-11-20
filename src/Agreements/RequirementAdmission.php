<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Support\Arr;
use ReflectionClass;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataContainer;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;

/** A transport-neutral read of the requirements plane; it never records assent or builds a response. */
class RequirementAdmission
{
    public function __construct(private RequirementResolver $resolver) {}

    /**
     * Return the declared refusal payload when outstanding requirements remain, or null when admitted.
     * The caller owns the transport status and presentation.
     *
     * @param  array<string, AgreementMemberData>  $submittedMembers  keyed by input member name
     */
    public function inspect(mixed $party, string $behavior, mixed $subject, array $submittedMembers): ?RequirementRefusalData
    {
        $requirements = $this->resolver->outstanding($party, $behavior, $subject, $submittedMembers);

        return $requirements === [] ? null : new RequirementRefusalData($requirements);
    }

    /**
     * Read only AgreementMemberData fields declared by the operation's input DTO.
     * Malformed members are omitted here so an outstanding requirement can still produce its
     * actionable 428; when no requirement is outstanding, the operation's normal input validation
     * remains responsible for reporting malformed input.
     *
     * @return array<string, AgreementMemberData>
     */
    public function submittedMembers(?string $input, array $payload): array
    {
        if ($input === null || ! is_subclass_of($input, Data::class)) {
            return [];
        }

        $members = [];
        $dataClass = DataContainer::get()->dataClassFactory()->build(new ReflectionClass($input));

        foreach ($dataClass->properties as $property) {
            $memberClass = $property->type->dataClass;

            if (! is_string($memberClass) || ! is_a($memberClass, AgreementMemberData::class, true)) {
                continue;
            }

            $name = $property->inputMappedName ?? $property->name;
            $value = Arr::get($payload, $name);

            if (! is_array($value)
                || ! is_string($value['key'] ?? null)
                || ! is_int($value['version'] ?? null)
                || ! is_string($value['digest'] ?? null)) {
                continue;
            }

            $members[$name] = new AgreementMemberData($value['key'], $value['version'], $value['digest']);
        }

        return $members;
    }
}
