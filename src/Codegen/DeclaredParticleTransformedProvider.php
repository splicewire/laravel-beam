<?php

namespace Splicewire\Beam\Codegen;

use Spatie\LaravelData\Contracts\BaseData;
use Spatie\TypeScriptTransformer\Actions\TransformTypesAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\TransformedProviders\ConfigAwareTransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\Visitor\Visitor;

/** Discover declared wire shapes through the host's configured transformers, including nested DTOs. */
final class DeclaredParticleTransformedProvider implements ConfigAwareTransformedProvider, TransformedProvider
{
    private TypeScriptTransformerConfig $config;

    public function __construct(private DeclaredParticleTypes $declaredTypes) {}

    public function setConfig(TypeScriptTransformerConfig $config): void
    {
        $this->config = $config;
    }

    public function provide(): array
    {
        $pending = array_keys($this->declaredTypes->declared());
        $visited = [];
        $types = [];
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

        return $types;
    }
}
