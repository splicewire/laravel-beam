<?php

namespace Splicewire\Beam\Particle\Attributes;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Splicewire\Beam\Particle\ParticleOperation as ParticleOperationRuntime;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource as ParticleResourceRuntime;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The reflect→register machinery behind {@see ParticleResource}/{@see ParticleOp} — the attribute twin of
 * the imperative `$registry->register(...)` provider block. It mirrors {@see \Splicewire\Beam\Frame\AdminResourceRegistry}
 * (the `#[AdminResource]` discovery), but targets the REST/op registries instead of the admin manifest.
 *
 * Because attributes cannot carry closures, the runtime `ParticleResource`/`ParticleOperation`'s closures
 * are resolved by CONVENTION from `public static` methods on the annotated class (see the attribute
 * docblocks for the exact signatures). Present methods are wrapped with {@see Closure::fromCallable} and
 * fed into the runtime object; absent ones leave the corresponding hook null.
 *
 * Discovery is idempotent: re-scanning re-registers by key (last-wins), never duplicates.
 */
class AttributedParticleDiscovery
{
    public function __construct(
        private ParticleResourceRegistry $resources,
        private ParticleOperationRegistry $operations,
    ) {}

    /**
     * Register the explicit class-strings and everything under the discover paths that carries a
     * `#[ParticleResource]` or `#[ParticleOp]` attribute.
     *
     * @param  array<int, class-string>  $classes  explicit annotated class list (cheap — always honoured)
     * @param  array<int, string>  $paths  filesystem paths to scan for annotated classes
     */
    public function discover(array $classes = [], array $paths = []): void
    {
        foreach ($classes as $class) {
            $this->registerClass($class);
        }

        $scanner = new AttributedClassScanner;

        foreach ($scanner->scan($paths, ParticleResource::class, instanceof: false) as $class) {
            $this->registerResourceClass($class);
        }

        foreach ($scanner->scan($paths, ParticleOp::class, instanceof: false) as $class) {
            $this->registerOpClass($class);
        }
    }

    /**
     * Reflect ONE explicitly-listed class and register whichever of the two attributes it carries (it may
     * carry both — a resource whose class also declares an op — though one-per-class is the norm).
     *
     * @param  class-string  $class
     */
    public function registerClass(string $class): void
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException("Particle attribute class [{$class}] does not exist.");
        }

        $reflection = new ReflectionClass($class);
        $registered = false;

        if (! empty($reflection->getAttributes(ParticleResource::class))) {
            $this->registerResourceClass($class);
            $registered = true;
        }

        if (! empty($reflection->getAttributes(ParticleOp::class))) {
            $this->registerOpClass($class);
            $registered = true;
        }

        if (! $registered) {
            throw new InvalidArgumentException(
                "Class [{$class}] carries neither #[ParticleResource] nor #[ParticleOp]."
            );
        }
    }

    /**
     * @param  class-string  $class
     */
    private function registerResourceClass(string $class): void
    {
        $attribute = (new ReflectionClass($class))->getAttributes(ParticleResource::class)[0]->newInstance();

        $this->resources->register(new ParticleResourceRuntime(
            key: $attribute->key,
            model: $attribute->model,
            // Single-class default: absent an explicit read Data class, the annotated class IS the projection.
            data: $attribute->data ?? $class,
            input: $attribute->input,
            includes: $attribute->includes,
            filterable: $attribute->filterable,
            perPage: $attribute->perPage,
            defaultSort: $attribute->defaultSort,
            prepare: $this->convention($class, 'prepare'),
            afterWrite: $this->convention($class, 'afterWrite'),
            project: $this->convention($class, 'project'),
            scope: $this->convention($class, 'scope'),
        ));
    }

    /**
     * @param  class-string  $class
     */
    private function registerOpClass(string $class): void
    {
        $attribute = (new ReflectionClass($class))->getAttributes(ParticleOp::class)[0]->newInstance();

        $handle = $this->convention($class, 'handle');

        if ($handle === null) {
            throw new InvalidArgumentException(
                "Particle op [{$attribute->resource}:{$attribute->name}] ({$class}) must declare a "
                .'`public static function handle(...)` — the op has no host code otherwise.'
            );
        }

        $this->operations->register(new ParticleOperationRuntime(
            resource: $attribute->resource,
            name: $attribute->name,
            kind: $attribute->kind,
            model: $attribute->model,
            handle: $handle,
            ability: $attribute->ability,
            abilityModel: $attribute->abilityModel,
            respond: $this->convention($class, 'respond'),
        ));
    }

    /**
     * Wrap a `public static` convention method as a Closure the runtime object accepts, or null if the
     * class doesn't declare it (each hook is optional but for the op's required `handle`).
     *
     * @param  class-string  $class
     */
    private function convention(string $class, string $method): ?Closure
    {
        return method_exists($class, $method)
            ? Closure::fromCallable([$class, $method])
            : null;
    }
}
