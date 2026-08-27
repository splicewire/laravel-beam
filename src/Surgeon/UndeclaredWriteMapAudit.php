<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;
use Splicewire\Beam\Write\ModelAttributeMapper;

/**
 * **The burn-down meter for the duck type.** Every class named in an `input:` or `editData:` slot that
 * declares neither {@see MapsToModelAttributes} nor the duck-typed `toModelAttributes()` method it
 * replaces.
 *
 * The write map was resolved by `method_exists()` for the whole life of the particle write surface — 25
 * implementations estate-wide, no interface, nothing that could find an implementor except `grep`
 * (`particle-write-surface` 03). {@see MapsToModelAttributes} declares it; this audit is the instrument
 * that says when the fallback may go. **When it reports zero, two things get deleted together**:
 * {@see ModelAttributeMapper::map()}'s `method_exists()` branch and
 * {@see ModelAttributeMapper::columns()}'s snake-case fallback, which is
 * `#[MapInputName(SnakeCaseMapper::class)]` written by hand and exists only because the contract did not.
 *
 * ## Three row kinds, and they are not equally strong signals
 *
 * - **`resource-input`** and **`resource-edit-data`** — the strong ones. A `#[ParticleResource]`'s
 *   `input:`/`editData:` class is handed *verbatim* to the generic transports' `toAttributes()`, so a
 *   class here with no map silently falls through to the snake-case fallback. That fallback cannot
 *   express a cleared column, cannot rename a field onto a differently-named column, and cannot derive
 *   one. Whether it is adequate for a given resource is a fact about the DTO, which is why this is
 *   checkable at all.
 * - **`write-op-input`** — the weaker one, and deliberately narrower than the slot. Only
 *   {@see OperationKind::Write} ops are considered: an op's handler is a closure that may build its own
 *   attributes and never ask the DTO for a column map at all, so a finding here is a *question*, not a
 *   defect. Read ops, tasks and streams are excluded entirely — their input is a query or a command, not
 *   a row.
 *
 * ## Advisory, and it will stay advisory until the count is zero
 *
 * Per the estate's rule that a check whose answer depends on the host must not throw: the POPULATION here
 * is a host fact (which resources this host registered), even though each ROW's verdict is a fact about
 * the declaration. More decisively, the fallback is still supported by construction — flagging a class
 * fatally for using a documented, working path would be a gate against beam's own code. This rides the
 * advisory-then-flip schedule `ParticleOperation`'s `input:`/`ability:` nulls already use.
 *
 * ⚠️ **A class may legitimately sit on this list forever.** A resource whose columns are exactly its
 * camelCase input fields, with no clearable column and no derived one, needs no map — the fallback is
 * correct for it. The right resolution for such a row is to implement the interface with a body that
 * spells out the trivial map, which turns "nobody thought about it" into "someone decided" and is the
 * whole point of declaring the contract.
 *
 * Advisory sibling of {@see ParticleWriteBypassAudit} and {@see ParticleOperationBypassAudit}; registered
 * in `BeamServiceProvider::registerParticleSurfaceAudits()`.
 */
class UndeclaredWriteMapAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'beam.particle.undeclared-write-map';

    public function __construct(
        protected ParticleResourceRegistry $resources,
        protected ParticleOperationRegistry $operations,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return array_map(fn (FixableFinding $f): Finding => $f->finding, $this->suggestOperations());
    }

    /**
     * @return list<FixableFinding>
     */
    public function suggestOperations(): array
    {
        $rows = $this->undeclared();

        if ($rows === []) {
            return [new FixableFinding(Finding::pass(
                self::CHECK,
                sprintf(
                    'Every input/editData class in the %d declared write slots maps its own columns (%d of them through %s).',
                    $this->slotCount(),
                    $this->declaredCount(),
                    class_basename(MapsToModelAttributes::class),
                ),
            ))];
        }

        return array_map(fn (array $row): FixableFinding => new FixableFinding(
            Finding::warn(self::CHECK, $this->detail($row)),
            OperationSuggestion::advisory($this->suggestion($row), 'splicewire/laravel-beam'),
        ), $rows);
    }

    /**
     * Every declared write slot whose class carries no column map at all, one row per CLASS (a class named
     * by several slots is one piece of work, not three).
     *
     * @return list<array{class: string, slots: list<string>, kind: string}>
     */
    public function undeclared(): array
    {
        $rows = [];

        foreach ($this->slots() as $slot) {
            [$class, $label, $kind] = $slot;

            if ($this->mapsItsOwnColumns($class)) {
                continue;
            }

            $rows[$class]['class'] = $class;
            $rows[$class]['kind'] = $rows[$class]['kind'] ?? $kind;
            $rows[$class]['slots'][] = $label;
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => $a['class'] <=> $b['class']);

        return $rows;
    }

    /**
     * The classes that DO declare the interface — the progress half of the meter, so a burn-down can be
     * read without diffing two runs.
     *
     * @return list<string>
     */
    public function declared(): array
    {
        $classes = [];

        foreach ($this->slots() as [$class]) {
            if (is_a($class, MapsToModelAttributes::class, true)) {
                $classes[$class] = true;
            }
        }

        $classes = array_keys($classes);
        sort($classes);

        return $classes;
    }

    /**
     * Every write slot this host declared, as `[class, label, kind]`.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function slots(): array
    {
        $slots = [];

        foreach ($this->resources->all() as $resource) {
            /** @var ParticleResource $resource */
            if (is_string($resource->input) && $resource->input !== '') {
                $slots[] = [$resource->input, "resource [{$resource->key}] input:", 'resource-input'];
            }

            if (is_string($resource->editData) && $resource->editData !== '') {
                $slots[] = [$resource->editData, "resource [{$resource->key}] editData:", 'resource-edit-data'];
            }
        }

        foreach ($this->operations->all() as $operation) {
            /** @var ParticleOperation $operation */
            if ($operation->kind !== OperationKind::Write) {
                continue;
            }

            if (! is_string($operation->input) || $operation->input === '') {
                continue;
            }

            $slots[] = [
                $operation->input,
                "write op [{$operation->resource}.{$operation->name}] input:",
                'write-op-input',
            ];
        }

        return $slots;
    }

    /**
     * Whether a declared class carries a column map — by contract first, then by the duck type the
     * contract supersedes. The order matters only for readability: an implementor satisfies both.
     */
    public function mapsItsOwnColumns(string $class): bool
    {
        if (! class_exists($class)) {
            // A slot naming a class that does not exist is a different defect with a different owner
            // (the registration would already have failed on use); reporting it as a missing write map
            // would aim the reader at the wrong seam.
            return true;
        }

        return is_a($class, MapsToModelAttributes::class, true)
            || method_exists($class, 'toModelAttributes');
    }

    protected function slotCount(): int
    {
        return count($this->slots());
    }

    protected function declaredCount(): int
    {
        return count($this->declared());
    }

    /**
     * @param  array{class: string, slots: list<string>, kind: string}  $row
     */
    protected function detail(array $row): string
    {
        return sprintf(
            '%s implements neither %s nor toModelAttributes(), so %s falls through to the snake-case fallback (%s).',
            $row['class'],
            class_basename(MapsToModelAttributes::class),
            count($row['slots']) === 1 ? 'the slot naming it' : 'every slot naming it',
            implode(', ', $row['slots']),
        );
    }

    /**
     * @param  array{class: string, slots: list<string>, kind: string}  $row
     */
    protected function suggestion(array $row): string
    {
        if ($row['kind'] === 'write-op-input') {
            return sprintf(
                'Declare %s implements %s if the op writes columns from it — or leave it if the handler '.
                'builds its attributes itself, which a write op legitimately may.',
                $row['class'],
                MapsToModelAttributes::class,
            );
        }

        return sprintf(
            'Declare %s implements %s and write out the column map — even a trivial one. Read the '.
            "interface's three-state rule first: an ABSENT field must be omitted (column untouched) and a ".
            'present-and-null one must be emitted (column cleared), which the snake-case fallback this '.
            'class currently rides cannot express.',
            $row['class'],
            MapsToModelAttributes::class,
        );
    }
}
