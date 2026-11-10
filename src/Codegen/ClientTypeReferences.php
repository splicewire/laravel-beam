<?php

namespace Splicewire\Beam\Codegen;

use LogicException;
use Spatie\TypeScriptTransformer\Actions\ResolveRelativePathAction;
use Spatie\TypeScriptTransformer\Data\GlobalNamespaceResolvedReference;
use Spatie\TypeScriptTransformer\Data\ModuleImportResolvedReference;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\TypeScriptTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;

/** Resolve SDK references with the same names, locations and writers as the host's generated DTOs. */
final class ClientTypeReferences
{
    /**
     * @param  array<string, mixed>  $model
     * @return array<string, array{name: string, module?: string}>
     */
    public function resolve(array $model, string $outDir, TypeScriptTransformerConfig $config): array
    {
        $references = [];
        foreach ($model['operations'] ?? [] as $operation) {
            if (isset($operation['returns']['ref'])) {
                $references[] = $operation['returns']['ref'];
            }
            // tutorial-dx 03: a mutation's body type is resolved (and must be emitted) exactly as its return type is.
            if (is_string($operation['meta']['input'] ?? null)) {
                $references[] = $operation['meta']['input'];
            }
            foreach ($operation['meta']['streams'] ?? [] as $variants) {
                array_push($references, ...$variants);
            }
        }
        if ($references === []) {
            return [];
        }

        // The client cannot emit a dangling type, so the first class it cannot resolve stops it.
        ['resolved' => $resolved, 'unresolved' => $unresolved] = $this->resolveClasses($references, $outDir, $config);
        foreach ($unresolved as $class => $reason) {
            throw new LogicException("Client DTO [{$class}] {$reason}.");
        }

        return $resolved;
    }

    /**
     * Resolve a list of classes with the host's writers, as {@see resolve()} does for a route model, and report
     * the ones that cannot be referenced instead of throwing (ruling 03 slice 2): a caller that can widen a
     * missing type (the frame resource map) decides what an unresolved class becomes.
     *
     * @param  list<string>  $classes  each in PHP (`Vendor\Pkg\Data`) or dot (`Vendor.Pkg.Data`) form
     * @return array{
     *     resolved: array<string, array{name: string, module?: string}>,
     *     unresolved: array<string, string>,
     * } keyed by each class as it was given; `unresolved` maps a PHP class name to why it has no reference (not emitted,
     *   or not exported by its module). A writer the transformer does not support still throws.
     */
    public function resolveClasses(array $classes, string $outDir, TypeScriptTransformerConfig $config): array
    {
        $resolved = [];
        $unresolved = [];
        if ($classes === []) {
            return ['resolved' => $resolved, 'unresolved' => $unresolved];
        }

        [$types] = TypeScriptTransformer::create($config)->resolveState();
        $types->ensureEachTransformedHasAWriter($config->typesWriter);
        foreach (array_unique($classes) as $reference) {
            $class = str_replace('.', '\\', $reference);
            $type = $types->get(new ClassStringReference($class));
            if ($type === null) {
                $unresolved[$class] = 'is not emitted by the configured TypeScript transformer';

                continue;
            }
            $target = $type->getWriter()->resolveReference($type);
            if ($target instanceof GlobalNamespaceResolvedReference) {
                $resolved[$reference] = ['name' => $target->qualifiedName];
            } elseif ($target instanceof ModuleImportResolvedReference) {
                if (! $type->isExported()) {
                    $unresolved[$class] = 'is not exported by its TypeScript module';

                    continue;
                }
                $module = (new ResolveRelativePathAction)->execute($this->physicalPath($outDir).'/aliases.ts', $config->outputDirectory.'/'.$target->path);
                $resolved[$reference] = ['name' => $target->name, 'module' => $module ?? './'];
            } else {
                // Unreachable with the transformer's two writers (its reference type is that union); a writer that
                // answers anything else is a configuration fault, not a missing type, so it still throws.
                throw new LogicException("Client DTO [{$class}] has no supported TypeScript writer reference.");
            }
        }

        return ['resolved' => $resolved, 'unresolved' => $unresolved];
    }

    /** Match the transformer's realpath normalization even before the SDK directory exists. */
    private function physicalPath(string $path): string
    {
        if (($physical = realpath($path)) !== false) {
            return $physical;
        }

        $parent = dirname($path);

        return $parent === $path ? $path : $this->physicalPath($parent).'/'.basename($path);
    }
}
