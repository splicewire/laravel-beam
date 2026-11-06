<?php

namespace Splicewire\Beam\Codegen;

use Spatie\LaravelData\Contracts\BaseData;
use Spatie\TypeScriptTransformer\Actions\TransformTypesAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\ConfigAwareTransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\Visitor\Visitor;

/**
 * Discover declared wire shapes through the host's configured transformers, including nested DTOs.
 *
 * Both seeds are *declared reach*: a class is a root here because a declaration names it, never because of
 * where its file lives.
 *
 *  - The **particle seed** ({@see DeclaredParticleTypes}): every resource and operation slot the particle
 *    registries declare.
 *  - The **route seed** ({@see DeclaredRouteTypes}, optional): every response body and stream payload a route
 *    declares, read the way the client manifest reads it, so each type a client references is a root too.
 *    A route-declared class that does not exist is skipped, left for `ClientTypeReferences` and
 *    `verify:declared-types` to name.
 *
 * The particle seed is walked first and behaves exactly as it does alone; the route seed then adds only what
 * the particle walk did not reach. Each root's nested `BaseData` and enum references are followed.
 */
final class DeclaredParticleTransformedProvider implements ConfigAwareTransformedProvider, TransformedProvider
{
    private TypeScriptTransformerConfig $config;

    public function __construct(
        private DeclaredParticleTypes $declaredTypes,
        private ?DeclaredRouteTypes $routeTypes = null,
    ) {}

    public function setConfig(TypeScriptTransformerConfig $config): void
    {
        $this->config = $config;
    }

    public function provide(): array
    {
        $visited = [];
        $types = [];

        $this->walk(array_keys($this->declaredTypes->declared()), $visited, $types);

        if ($this->routeTypes !== null) {
            $this->walk(array_values(array_filter(
                array_keys($this->routeTypes->declared()),
                fn (string $class): bool => class_exists($class) || interface_exists($class) || enum_exists($class),
            )), $visited, $types);
        }

        return $types;
    }

    /**
     * @param  list<string>  $pending
     * @param  array<string, true>  $visited
     * @param  list<Transformed>  $types
     */
    private function walk(array $pending, array &$visited, array &$types): void
    {
        $action = new TransformTypesAction;

        while ($pending !== []) {
            $class = array_pop($pending);
            if (isset($visited[$class])) {
                continue;
            }
            $visited[$class] = true;

            $transformed = $action->transformClassNode($this->config->transformers, PhpClassNode::fromClassString($class));
            if ($transformed === null) {
                continue;
            }

            Visitor::create()->before(function (TypeScriptReference $reference) use (&$pending): void {
                if (! $reference->reference instanceof ClassStringReference) {
                    return;
                }
                $class = $reference->reference->classString;
                if (is_a($class, BaseData::class, true) || enum_exists($class)) {
                    $pending[] = $class;
                }
            }, [TypeScriptReference::class])->execute($transformed->getNode());

            $types[] = $transformed;
        }
    }
}
