<?php

namespace Splicewire\Beam\Localization;

use ReflectionProperty;
use Schemastud\DataSchemas\Strategies\SchemaStrategy;
use Schemastud\DataSchemas\Strategies\SchemaStrategyContext;

/**
 * A property's `title` and `description`, resolved for the request's language and realm (localization ticket 09). The
 * generator sets both from `#[Title]` / `#[Description]` before the property strategies run, so this strategy rewrites
 * them and laravel-data-schemas does not change for properties.
 *
 * The keys are ruling 01 §2's, owned by the class that DECLARES the property:
 * `<ns>::data.<Class>.properties.<property>.title` / `.description`. A property with no declared title gets one only
 * when a lang line supplies it; otherwise the schema is unchanged.
 */
final class TranslatedPropertyTextStrategy implements SchemaStrategy
{
    public function __construct(private DeclaredText $text) {}

    public function apply(ReflectionProperty $property, array $schema, SchemaStrategyContext $context): array
    {
        $class = $property->getDeclaringClass()->getName();

        foreach (['title', 'description'] as $slot) {
            $declared = isset($schema[$slot]) && is_string($schema[$slot]) ? $schema[$slot] : null;
            $text = $this->text->for('data', $class, 'properties.'.$property->getName().'.'.$slot, $declared);

            if ($text !== null) {
                $schema[$slot] = $text;
            }
        }

        return $schema;
    }
}
