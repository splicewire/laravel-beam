<?php

namespace Splicewire\Beam\Agreements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use LogicException;
use ReflectionClass;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\DataContainer;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\Data\RequirementRefusalData;

/** A transport-neutral read of the requirements plane; it never records assent or builds a response. */
class RequirementAdmission
{
    public function __construct(private RequirementResolver $resolver) {}

    /**
     * Return the declared refusal payload when outstanding requirements remain, or null when admitted.
     * The caller owns the transport status and presentation.
     *
     * @param  array<string, AgreementMemberData>  $submittedMembers  keyed by Agreement key
     */
    public function inspect(mixed $party, string $behavior, mixed $subject, array $submittedMembers, ?string $input = null): ?RequirementRefusalData
    {
        $requirements = $this->resolver->outstanding($party, $behavior, $subject, $submittedMembers);

        if ($requirements === []) {
            return null;
        }

        $memberInput = null;
        foreach ($requirements as $index => $requirement) {
            $needsMember = $requirement->scope === 'occurrence'
                || ($requirement->scope === 'subject' && ! ($subject instanceof Model));

            if (! $needsMember || $requirement->acceptOperation === null) {
                continue;
            }

            $memberInput ??= $this->memberInputName($input);
            if ($memberInput === null) {
                throw new LogicException(sprintf(
                    'Occurrence requirement [%s] for [%s] has no declared AgreementMemberData input.',
                    $requirement->key,
                    $behavior,
                ));
            }

            $requirements[$index] = new OutstandingRequirementData(
                key: $requirement->key,
                version: $requirement->version,
                digest: $requirement->digest,
                kind: $requirement->kind,
                title: $requirement->title,
                scope: $requirement->scope,
                subject: $requirement->subject,
                readUrl: $requirement->readUrl,
                acceptOperation: null,
                member: $memberInput,
            );
        }

        return new RequirementRefusalData($requirements);
    }

    /**
     * Read only AgreementMemberData fields declared by the operation's input DTO.
     * Malformed members are omitted here so an outstanding requirement can still produce its
     * actionable 428; when no requirement is outstanding, the operation's normal input validation
     * remains responsible for reporting malformed input.
     *
     * @return array<string, AgreementMemberData> keyed by Agreement key; duplicate keys use the last list entry
     */
    public function submittedMembers(?string $input, array $payload): array
    {
        $members = [];

        foreach ($this->memberInputProperties($input) as [$name, $memberClass]) {
            $value = Arr::get($payload, $name);

            if (! is_array($value)) {
                continue;
            }

            // A single AgreementMemberData remains supported. DataCollectionOf<AgreementMemberData> lets one form
            // carry a member for each Agreement; normalizing by key lets every resolver consume both shapes alike.
            $values = self::isMember($value) ? [$value] : $value;
            foreach ($values as $candidate) {
                if (self::isMember($candidate)) {
                    // A duplicate Agreement key is deterministic: the last submitted member wins. Admission still
                    // compares its version and digest to the in-force version before admitting the operation.
                    $members[$candidate['key']] = new AgreementMemberData(
                        $candidate['key'],
                        $candidate['version'],
                        $candidate['digest'],
                    );
                }
            }
        }

        return $members;
    }

    /** Return the declared AgreementMemberData input property that can represent the refusal's remedies. */
    private function memberInputName(?string $input): ?string
    {
        $properties = $this->memberInputProperties($input);
        if ($properties === []) {
            return null;
        }

        $collections = array_values(array_filter($properties, fn (array $property): bool => $property[2]));
        if (count($collections) === 1) {
            return $collections[0][0];
        }

        if (count($collections) > 1 || count($properties) > 1) {
            $names = array_column($collections ?: $properties, 0);

            throw new LogicException(sprintf(
                'Input [%s] declares multiple AgreementMemberData properties [%s].',
                $input,
                implode(', ', $names),
            ));
        }

        return $properties[0][0];
    }

    /** @return list<array{string, class-string<AgreementMemberData>, bool}> */
    private function memberInputProperties(?string $input): array
    {
        if ($input === null || ! is_subclass_of($input, Data::class)) {
            return [];
        }

        $properties = [];
        $dataClass = DataContainer::get()->dataClassFactory()->build(new ReflectionClass($input));

        foreach ($dataClass->properties as $property) {
            $memberClass = $property->type->dataClass;

            if (! is_string($memberClass) || ! is_a($memberClass, AgreementMemberData::class, true)) {
                continue;
            }

            $properties[] = [$property->inputMappedName ?? $property->name, $memberClass, $property->type->dataCollectableClass !== null];
        }

        return $properties;
    }

    private static function isMember(mixed $value): bool
    {
        return is_array($value)
            && is_string($value['key'] ?? null)
            && is_int($value['version'] ?? null)
            && is_string($value['digest'] ?? null);
    }
}
