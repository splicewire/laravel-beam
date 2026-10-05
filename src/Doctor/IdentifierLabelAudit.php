<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Schema\IdentifierLabels;
use Splicewire\Beam\Testing\AssertsIdentifierLabels;

/**
 * `schema.identifier-label` (app-walkthrough APP-09, APP-21): the classes reachable from this host's particle
 * `input:`/`output:` (and resource `data:`/`input:`) whose rendered label would be an identifier. That is a Data
 * class with no #[Title], or an enum with no ProvidesEnumLabel. A warn, ratcheted down per host by
 * {@see AssertsIdentifierLabels}: each fix is a #[Title] or a label() on the class itself.
 */
class IdentifierLabelAudit implements DoctorAudit
{
    public const CHECK = 'schema.identifier-label';

    public function __construct(private IdentifierLabels $labels) {}

    public function run(): array
    {
        $found = $this->labels->find();

        if ($found === []) {
            return [Finding::pass(self::CHECK, 'Every class reachable from a particle input/output declares its label (#[Title], or ProvidesEnumLabel on an enum).')];
        }

        $objects = array_keys(array_filter($found, fn (string $unit) => $unit === 'object'));
        $enums = array_keys(array_filter($found, fn (string $unit) => $unit === 'enum'));

        return [Finding::warn(self::CHECK, sprintf(
            '%d class%s reachable from a particle input/output would render an identifier as its label. '
            .'Data with no #[Title] (%d): %s. Enums with no ProvidesEnumLabel, whose options show backing values (%d): %s.',
            count($found),
            count($found) === 1 ? '' : 'es',
            count($objects),
            $objects === [] ? 'none' : implode('; ', $objects),
            count($enums),
            $enums === [] ? 'none' : implode('; ', $enums),
        ))];
    }
}
