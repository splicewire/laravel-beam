<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionProperty;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\NameMapper;
use Splicewire\Beam\Data\Attributes\WireNameExemption;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Throwable;

/**
 * **The burn-down meter for undeclared wire names.** Every multi-word property on a Data class that
 * declares no property-level name mapping — and therefore publishes whatever the HOST's global
 * `data.name_mapping_strategy` happens to produce.
 *
 * ## Why this is worth an audit, when the column map already has one
 *
 * {@see UndeclaredWriteMapAudit} asks "did you declare how this DTO maps onto COLUMNS". This asks "did
 * you declare what a client must SEND", which is the more load-bearing of the two: a column map decides
 * what a write stores inside one application, a wire name is a published contract other people build
 * against.
 *
 * The estate had the first and not the second. Measured 2026-08-28 on
 * `splicewire/laravel-beam-calendars`: fifteen Data classes declared NEITHER mapping axis, so under the
 * flagship's `input => CamelCaseMapper` / `output => null` the package **emitted `calendar_id` and
 * demanded `calendarId` for the same field**. Read one key, write another. Nothing anywhere reported it,
 * and it shipped.
 *
 * The same shape recurs the other way: `api-surface-coherence` 100 found 60 properties spelled snake in
 * PHP purely to defeat a global camel mapper. Both are the same defect — **the global mapper deciding a
 * package's published contract by default** — and neither direction was visible to any instrument.
 *
 * ## What counts as declared
 *
 * `#[MapName]`, or the attribute for the inspected direction: `#[MapInputName]` on an input slot and
 * `#[MapOutputName]` on an output slot. An input-only declaration cannot pin an output contract, nor
 * vice versa. A class-level mapper does not declare a property's wire name: the host's global mapper
 * takes precedence over it, so a package relying on the class attribute still publishes a
 * host-dependent contract.
 *
 * Slot direction is part of the fact. Resource `input`/`editData` and operation `input` are inspected
 * against the input mapper; resource `data`/`createResultData` and operation `output` against the
 * output mapper. Flattening those slots made output DTOs look broken merely because a host camelized
 * inputs while deliberately leaving responses alone.
 *
 * ## ⚠️ Single-word properties are not findings
 *
 * Every mapper in the box — camel, snake, kebab, studly — is the identity on `$id` and `$channel`. There
 * is nothing a declaration could disambiguate, so a row there says only that a property is short. The
 * whole value of this audit is that its list is short enough to read; reporting the identity cases would
 * bury the real rows under an order of magnitude of noise.
 *
 * ## Advisory, permanently
 *
 * The POPULATION is a host fact — which Data classes a given host ships — and by the estate's rule a
 * check whose answer depends on the host must not throw. A class that cannot be reflected at all is
 * reported as a skipped row rather than taking the sweep down with it, for the same reason: this must
 * survive a host whose classmap is stale.
 *
 * Advisory sibling of {@see UndeclaredWriteMapAudit}.
 */
class WireNameDeclarationAudit implements DoctorAudit
{
    public const CHECK = 'beam.particle.undeclared-wire-name';

    /** @var array<class-string, array{input: bool, output: bool}> */
    private array $classes;

    /** @var array<string, true> */
    private array $documentedExemptions = [];

    /**
     * An unkeyed list means both axes for callers without registry slot information;
     * {@see forRegistries()} supplies the exact declared directions.
     *
     * @param  array<int|string, string|list<'input'|'output'>>  $classes
     * @param  class-string|null  $input  the host's configured global INPUT name mapper
     * @param  class-string|null  $output  the host's configured global OUTPUT name mapper
     */
    public function __construct(
        array $classes,
        private ?string $input = null,
        private ?string $output = null,
    ) {
        $this->classes = [];

        foreach ($classes as $class => $axes) {
            if (is_int($class)) {
                $this->classes[$axes] = ['input' => true, 'output' => true];

                continue;
            }

            $this->classes[$class] = [
                'input' => in_array('input', $axes, true),
                'output' => in_array('output', $axes, true),
            ];
        }
    }

    /**
     * The estate population: every Data class named in a DECLARED slot — a resource's `data:`,
     * `input:`, `editData:` and `createResultData:`, and an operation's `input:` and `output:`.
     *
     * That is exactly the particle doctrine's own scope ("every boundary-crossing data shape is a
     * declared Data class"), which is what makes the population defensible rather than arbitrary: a
     * class nobody declared is not on a wire, so its casing is a style question and not a contract.
     *
     * Reads the REGISTRIES rather than scanning source, for the same reason
     * {@see UndeclaredWriteMapAudit} does — the slot is a registered value, not a syntactic one.
     */
    public static function forRegistries(
        ParticleResourceRegistry $resources,
        ParticleOperationRegistry $operations,
        ?string $input = null,
        ?string $output = null,
    ): self {
        /** @var array<class-string, array{input?: true, output?: true}> $classes */
        $classes = [];

        $add = static function (mixed $slot, string $axis) use (&$classes): void {
            if (is_string($slot) && $slot !== '') {
                $classes[$slot][$axis] = true;
            }
        };

        foreach ($resources->all() as $resource) {
            $add($resource->data ?? null, 'output');
            $add($resource->input ?? null, 'input');
            $add($resource->editData ?? null, 'input');
            $add($resource->createResultData ?? null, 'output');
        }

        foreach ($operations->all() as $operation) {
            // A Stream op's `output:` is an EVENT-NAME MAP, not a class-string — flattening it here
            // rather than letting the array reach the reflection loop as a "class".
            $outputs = is_array($operation->output ?? null) ? $operation->output : [$operation->output ?? null];

            foreach ($outputs as $slot) {
                $add($slot, 'output');
            }

            $add($operation->input ?? null, 'input');
        }

        return new self(array_map(
            static fn (array $axes): array => array_keys($axes),
            $classes,
        ), $input, $output);
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $findings = [];
        $this->documentedExemptions = [];

        foreach ($this->classes as $class => $axes) {
            try {
                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                // A host fact, not a declaration defect — see the class docblock.
                $findings[] = Finding::warn(self::CHECK, sprintf('[%s] could not be reflected; skipped.', $class));

                continue;
            }

            foreach ($this->undeclaredProperties($reflection, $axes) as $row) {
                $findings[] = Finding::warn(self::CHECK, $row['hostMapped']
                    ? sprintf(
                        '%s::$%s declares no wire name, and the host\'s global %s mapper rewrites it to '
                        ."'%s' — so the mapper is choosing this package's published key, not the author. "
                        ."Declare the intended one with #[Map%sName('%s')].",
                        $reflection->getShortName(),
                        $row['property'],
                        $row['axis'],
                        $row['published'],
                        ucfirst($row['axis']),
                        $row['property'],
                    )
                    : sprintf(
                        "%s::$%s publishes the bare snake output key '%s', while the package wire standard requires '%s'. "
                        .'Rename the property camelCase and explicitly map its source/storage name.',
                        $reflection->getShortName(),
                        $row['property'],
                        $row['property'],
                        $row['published'],
                    ));
            }
        }

        return $findings === []
            ? [Finding::pass(self::CHECK, sprintf(
                '%d Data class(es) declare their wire names; %d documented wire-name exemption(s).',
                count($this->classes),
                count($this->documentedExemptions),
            ))]
            : $findings;
    }

    /**
     * @param  array{input: bool, output: bool}  $axes
     * @return list<array{property: string, axis: string, published: string, hostMapped: bool}>
     */
    private function undeclaredProperties(ReflectionClass $reflection, array $axes): array
    {
        $undeclared = [];

        foreach ($reflection->getProperties() as $property) {
            if (! $property->isPublic() || $property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            $exemption = $this->exemptionFor($reflection, $property);

            foreach (['input' => $this->input, 'output' => $this->output] as $axis => $mapper) {
                if (! $axes[$axis] || $this->declares($property, $axis)) {
                    continue;
                }

                $published = $this->publishedKey($mapper, $name);

                // Package-owned output DTOs publish camelCase even on hosts that configure no
                // output mapper. A bare snake PHP property therefore remains a contract defect in
                // the identity-mapper posture: there is no host rewrite for the older rule below
                // to observe, but the authored output key itself violates the declared standard.
                if ($axis === 'output' && str_contains($name, '_') && $published === null) {
                    if ($exemption !== null) {
                        continue;
                    }

                    $undeclared[] = [
                        'property' => $name,
                        'axis' => $axis,
                        'published' => Str::camel($name),
                        'hostMapped' => false,
                    ];

                    break;
                }

                // ⚠️ THE WHOLE RULE. Report only where a CONFIGURED global mapper would CHANGE the
                // name — that is the condition "the mapper is deciding this package's contract".
                // An identity (no mapper on that axis, or a mapper that leaves the name alone) means
                // the property name IS the key, deterministically, and there is nothing to declare.
                //
                // The first version of this audit skipped this test and flagged every multi-word
                // property. At the flagship that was 232 findings, 212 of them camelCase READ
                // properties under `output => null` whose keys were already correct — and its
                // suggestion would have renamed all 212 on the wire. An audit that recommends a
                // breaking change to a correct declaration is worse than no audit.
                if ($published !== null && $published !== $name) {
                    $undeclared[] = [
                        'property' => $name,
                        'axis' => $axis,
                        'published' => $published,
                        'hostMapped' => true,
                    ];
                    break;
                }
            }
        }

        return $undeclared;
    }

    private function declares(ReflectionProperty $property, string $axis): bool
    {
        if ($property->getAttributes(MapName::class) !== []) {
            return true;
        }

        $attribute = $axis === 'input' ? MapInputName::class : MapOutputName::class;

        return $property->getAttributes($attribute) !== [];
    }

    private function exemptionFor(ReflectionClass $class, ReflectionProperty $property): ?WireNameExemption
    {
        $attributes = $property->getAttributes(WireNameExemption::class);
        $key = $class->getName().'::$'.$property->getName();

        if ($attributes === []) {
            $attributes = $class->getAttributes(WireNameExemption::class);
            $key = $class->getName();
        }

        if ($attributes === []) {
            return null;
        }

        $this->documentedExemptions[$key] = true;

        return $attributes[0]->newInstance();
    }

    /**
     * What the configured mapper publishes for this property, or null when that axis has no mapper.
     *
     * A mapper that cannot be instantiated is treated as absent rather than fatal — the mapper is a
     * HOST config value, and a check whose answer depends on the host must not throw.
     */
    private function publishedKey(?string $mapper, string $name): ?string
    {
        if ($mapper === null || $mapper === '' || ! class_exists($mapper)) {
            return null;
        }

        try {
            $instance = new $mapper;

            return $instance instanceof NameMapper ? (string) $instance->map($name) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
