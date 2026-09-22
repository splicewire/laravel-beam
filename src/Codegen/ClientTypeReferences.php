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
            foreach ($operation['meta']['streams'] ?? [] as $variants) {
                array_push($references, ...$variants);
            }
        }
        if ($references === []) {
            return [];
        }

        [$types] = TypeScriptTransformer::create($config)->resolveState();
        $types->ensureEachTransformedHasAWriter($config->typesWriter);
        $resolved = [];
        foreach (array_unique($references) as $reference) {
            $class = str_replace('.', '\\', $reference);
            $type = $types->get(new ClassStringReference($class));
            if ($type === null) {
                throw new LogicException("Client DTO [{$class}] is not emitted by the configured TypeScript transformer.");
            }
            $target = $type->getWriter()->resolveReference($type);
            if ($target instanceof GlobalNamespaceResolvedReference) {
                $resolved[$reference] = ['name' => $target->qualifiedName];
            } elseif ($target instanceof ModuleImportResolvedReference) {
                if (! $type->isExported()) {
                    throw new LogicException("Client DTO [{$class}] is not exported by its TypeScript module.");
                }
                $module = (new ResolveRelativePathAction)->execute($this->physicalPath($outDir).'/aliases.ts', $config->outputDirectory.'/'.$target->path);
                $resolved[$reference] = ['name' => $target->name, 'module' => $module ?? './'];
            } else {
                throw new LogicException("Client DTO [{$class}] has no supported TypeScript writer reference.");
            }
        }

        return $resolved;
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
