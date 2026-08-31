<?php

namespace Splicewire\Beam\Codegen;

use ReflectionClass;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Frame\ParticleResourceRegistryAdapter;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * Every DTO class the particle estate DECLARES, and the fully-qualified dotted name each one should
 * emit as in the ambient TypeScript tree.
 *
 * ## Why this is the same defect {@see AmbientTypeIndex} was built for, one tier wider
 *
 * `AmbientTypeIndex`'s docblock names the recurring failure precisely: *a HOST-ROOTED default scan
 * cannot see a PACKAGE's discoverable assets*, and "fails to emit silently" is the most-repeated defect
 * on the particle-contribution-seam map. {@see ContributedTypesGenerator} converts that into a named
 * generation-time failure — but only for the handful of DTOs a contribution SLICE happens to reference.
 * Every other declared shape on the estate (a resource's `data`, its `input`, its `editData`; an
 * operation's `input` and `output`) is exposed to the identical failure and nothing looks at it. A
 * `#[ParticleResource]` in a package whose `src/Data` root the host's `#[TypeScript]` scan never reaches
 * ships an endpoint whose response has no frontend type at all, and the first symptom is a hand-written
 * `any` months later.
 *
 * This class is the enumeration half of closing that. It answers ONE question — "what shapes does this
 * host declare, and what would each be called if it emitted?" — and answers it from registries alone.
 *
 * ## Registries in, class-strings out
 *
 * The same split {@see ContributedTypesGenerator} uses: no filesystem, no config reads, no container.
 * {@see declared()} is reflection-free as well, so it is assertable from hand-registered fixtures. Only
 * {@see partition()} reflects, because "did this class ASK to be exported" is a fact about the class
 * rather than about the declaration that names it.
 *
 * ## The opt-out is `#[TypeScript]`'s own absence — no new mechanism
 *
 * Not every declared DTO is public frontend API, and beam has zero `#[Hidden]` usages, so there is no
 * established opt-out to lean on. Rather than invent one, this reads the opt-IN that already exists:
 * a class carrying `#[TypeScript]` has ASKED to be in the emitted tree, so its absence from that tree is
 * unambiguously a defect and is reportable as one. A class carrying no `#[TypeScript]` has asked for
 * nothing, and whether this host emits it depends on collectors this class cannot see (spatie-laravel-data's
 * `DataTypeScriptCollector` transforms annotated and unannotated Data classes alike, depending on host
 * config). Those two facts are kept in two buckets on purpose: "declared to export and did not" and
 * "never declared to export, so we could not look" are different facts, and only the first is a failure.
 *
 * ## The name derivation is spatie's, not ours
 *
 * `GlobalNamespaceWriter::resolveReference()` joins the transformed type's LOCATION and NAME with dots,
 * which for a default-located class is exactly the dotted FQCN {@see ContributedTypesGenerator::reference()}
 * already produces. `#[TypeScript(name:)]` overrides the last segment and `#[TypeScript(location:)]`
 * (v3) overrides the leading ones, so both are honoured — reading them off the attribute's RAW arguments
 * rather than instantiating it, which keeps this working under beam's `^2.0|^3.0` constraint where
 * `location` does not exist.
 */
class DeclaredParticleTypes
{
    public function __construct(
        protected ParticleResourceRegistry $resources,
        protected ParticleOperationRegistry $operations,
    ) {}

    /**
     * Every DTO class-string the two particle registries declare, mapped to the declaration slots that
     * named it.
     *
     * Deduplicated by class (one Data class commonly serves several resources and ops) with registration
     * order preserved, and the slot list is what makes a failure legible — a bare class name leaves the
     * reader hunting for which declaration is broken.
     *
     * Frame's resources are deliberately NOT enumerated separately: `Schemastud\Frame`'s `ResourceRegistry`
     * port is fed by {@see ParticleResourceRegistryAdapter}, a stateless forwarder
     * with no store of its own, so a framed resource IS a row of `ParticleResourceRegistry` and reading it
     * twice would double-count rather than widen.
     *
     * @return array<class-string, list<string>> class => declaration slots, registration order
     */
    public function declared(): array
    {
        $declared = [];

        $note = function (mixed $class, string $slot) use (&$declared): void {
            // `input` is three-state (`class-string|false|null`) and `data`/`editData` are nullable — only
            // a class-string is a declared shape. `false` is a declared REFUSAL and `null` is the
            // undeclared residue; neither names a type, so neither can be missing from the tree.
            if (! is_string($class) || trim($class) === '') {
                return;
            }

            $declared[ltrim($class, '\\')][] = $slot;
        };

        foreach ($this->resources->all() as $resource) {
            if (! $resource instanceof ParticleResource) {
                continue;
            }

            $note($resource->data, "resource [{$resource->key}] data:");
            $note($resource->input, "resource [{$resource->key}] input:");
            $note($resource->editData, "resource [{$resource->key}] editData:");
        }

        foreach ($this->operations->all() as $operation) {
            if (! $operation instanceof ParticleOperation) {
                continue;
            }

            $key = "{$operation->resource}.{$operation->name}";

            $note($operation->input, "operation [{$key}] input:");

            // ⚠️ `output` is kind-dependent and the asymmetry is load-bearing: a read/write/task resolves
            // ONE payload (a class-string), while a Stream emits discrete typed events under distinct wire
            // names and so declares `[eventName => [DataClass, ...]]`. `ParticleOperation` enforces the
            // pairing in its constructor, so both branches are reachable and neither is defensive. The
            // per-event value is a LIST because one event name may cover several payload variants
            // discriminated by a DTO field — flattening it to a single class would lose variants silently,
            // which is the exact failure mode this whole class exists to remove.
            $output = $operation->output;

            if (is_string($output)) {
                $note($output, "operation [{$key}] output:");

                continue;
            }

            if (! is_array($output)) {
                continue;
            }

            foreach ($output as $event => $payloads) {
                foreach (is_array($payloads) ? $payloads : [$payloads] as $payload) {
                    $note($payload, "operation [{$key}] output: [{$event}]");
                }
            }
        }

        return $declared;
    }

    /**
     * Split declared classes into the three answers this check can honestly give.
     *
     *  - `exported`  — carries `#[TypeScript]`, so it asked to emit; mapped to the dotted name it should
     *                  emit under. These are checkable, and a miss is a defect.
     *  - `unexported` — carries no `#[TypeScript]`. Whether this host emits it is a function of collectors
     *                  this class cannot see, so it is COUNTED, never failed. This is the opt-out.
     *  - `absent`    — the declaration names a class that does not exist. Not a TypeScript problem at all;
     *                  surfaced here because this is the first pass that looks.
     *
     * @param  list<class-string>  $classes
     * @return array{exported: array<class-string, string>, unexported: list<class-string>, absent: list<class-string>}
     */
    public function partition(array $classes): array
    {
        $exported = [];
        $unexported = [];
        $absent = [];

        foreach ($classes as $class) {
            if (! class_exists($class) && ! interface_exists($class)) {
                $absent[] = $class;

                continue;
            }

            $attributes = (new ReflectionClass($class))->getAttributes(TypeScript::class);

            if ($attributes === []) {
                $unexported[] = $class;

                continue;
            }

            // Raw arguments rather than `newInstance()`: `location:` is a v3-only parameter and beam
            // declares `^2.0|^3.0`, so instantiating would be the one thing that could break under v2.
            $arguments = $attributes[0]->getArguments();

            $name = $arguments['name'] ?? $arguments[0] ?? null;
            $location = $arguments['location'] ?? $arguments[1] ?? null;

            $exported[$class] = $this->emittedName(
                $class,
                is_string($name) ? $name : null,
                is_array($location) ? $location : null,
            );
        }

        return ['exported' => $exported, 'unexported' => $unexported, 'absent' => $absent];
    }

    /**
     * The dotted type name spatie's `GlobalNamespaceWriter` writes for a class — its LOCATION segments
     * joined to its NAME.
     *
     * With neither override this is `str_replace('\\', '.', $class)`, character for character what
     * {@see ContributedTypesGenerator::reference()} already produces and what {@see AmbientTypeIndex}
     * already parses. Nothing new is invented here; the two overrides are the only reason this needs to
     * be a method rather than one `str_replace`.
     *
     * @param  list<string>|null  $location
     */
    protected function emittedName(string $class, ?string $name, ?array $location): string
    {
        $class = ltrim($class, '\\');
        $separator = strrpos($class, '\\');

        $short = $separator === false ? $class : substr($class, $separator + 1);
        $namespace = $separator === false ? '' : substr($class, 0, $separator);

        $segments = $location ?? ($namespace === '' ? [] : explode('\\', $namespace));

        return implode('.', [...array_values($segments), $name ?? $short]);
    }
}
