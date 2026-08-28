<?php

namespace Splicewire\Beam\Surgeon;

use ReflectionClass;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Mappers\NameMapper;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Throwable;

/**
 * **The burn-down meter for undeclared wire names.** Every multi-word property on a Data class that
 * declares neither its own name mapping nor a class-level one — and therefore publishes whatever the
 * HOST's global `data.name_mapping_strategy` happens to produce.
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
 * Any of `#[MapName]`, `#[MapInputName]`, `#[MapOutputName]` on the property, **or** on the class. A
 * class-level mapper is a deliberate decision even though it names no key per property, so it clears
 * every property under it.
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

    /** @var list<class-string> */
    private array $classes;

    /**
     * @param  list<class-string>  $classes  the Data classes to inspect. Passed in rather than
     *                                       discovered here so the caller owns the population — a host
     *                                       audits its own paths, and a test audits fixtures.
     * @param  class-string|null  $input  the host's configured global INPUT name mapper
     * @param  class-string|null  $output  the host's configured global OUTPUT name mapper
     */
    public function __construct(
        array $classes,
        private ?string $input = null,
        private ?string $output = null,
    ) {
        $this->classes = array_values($classes);
    }

    /**
     * The estate population: every Data class named in a DECLARED slot — a resource's `data:`,
     * `input:` and `editData:`, and an operation's `input:` and `output:`.
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
        $classes = [];

        foreach ($resources->all() as $resource) {
            foreach ([$resource->data ?? null, $resource->input ?? null, $resource->editData ?? null] as $slot) {
                if (is_string($slot) && $slot !== '') {
                    $classes[$slot] = true;
                }
            }
        }

        foreach ($operations->all() as $operation) {
            // A Stream op's `output:` is an EVENT-NAME MAP, not a class-string — flattening it here
            // rather than letting the array reach the reflection loop as a "class".
            $outputs = is_array($operation->output ?? null) ? $operation->output : [$operation->output ?? null];

            foreach ([...$outputs, $operation->input ?? null] as $slot) {
                foreach ((array) $slot as $candidate) {
                    if (is_string($candidate) && $candidate !== '') {
                        $classes[$candidate] = true;
                    }
                }
            }
        }

        return new self(array_keys($classes), $input, $output);
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $findings = [];

        foreach ($this->classes as $class) {
            try {
                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                // A host fact, not a declaration defect — see the class docblock.
                $findings[] = Finding::warn(self::CHECK, sprintf('[%s] could not be reflected; skipped.', $class));

                continue;
            }

            if ($this->classDeclaresMapping($reflection)) {
                continue;
            }

            foreach ($this->undeclaredProperties($reflection) as $row) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '%s::$%s declares no wire name, and the host\'s global %s mapper rewrites it to '
                    ."'%s' — so the mapper is choosing this package's published key, not the author. "
                    ."Declare the intended one with #[MapName('%s')].",
                    $reflection->getShortName(),
                    $row['property'],
                    $row['axis'],
                    $row['published'],
                    $row['property'],
                ));
            }
        }

        return $findings === []
            ? [Finding::pass(self::CHECK, sprintf('%d Data class(es) declare their wire names.', count($this->classes)))]
            : $findings;
    }

    private function classDeclaresMapping(ReflectionClass $reflection): bool
    {
        foreach ([MapName::class, MapInputName::class, MapOutputName::class] as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{property: string, axis: string, published: string}>
     */
    private function undeclaredProperties(ReflectionClass $reflection): array
    {
        $undeclared = [];

        foreach ($reflection->getProperties() as $property) {
            if (! $property->isPublic() || $property->isStatic()) {
                continue;
            }

            $declared = false;
            foreach ([MapName::class, MapInputName::class, MapOutputName::class] as $attribute) {
                if ($property->getAttributes($attribute) !== []) {
                    $declared = true;
                    break;
                }
            }

            if ($declared) {
                continue;
            }

            $name = $property->getName();

            foreach (['input' => $this->input, 'output' => $this->output] as $axis => $mapper) {
                $published = $this->publishedKey($mapper, $name);

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
                    $undeclared[] = ['property' => $name, 'axis' => $axis, 'published' => $published];
                    break;
                }
            }
        }

        return $undeclared;
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

    /**
     * Multi-word in EITHER spelling — `calendarId` and `calendar_id` are the same property under two
     * conventions, and both publish a key the author did not choose. Testing only for camel humps would
     * miss the entire population `api-surface-coherence` 100 is about.
     */
    private function isMultiWord(string $name): bool
    {
        return str_contains($name, '_') || preg_match('/[a-z][A-Z]/', $name) === 1;
    }

    private function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
