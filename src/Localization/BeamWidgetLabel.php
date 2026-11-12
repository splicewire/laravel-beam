<?php

namespace Splicewire\Beam\Localization;

use ReflectionProperty;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Contracts\WidgetLabel;

/**
 * laravel-frame's widget-context label hook, bound to beam's ladder (localization ticket 10, ruling 01 §6).
 *
 * For each locale in the locale chain (outer), at each realm rung (inner):
 *
 *   1. the context key, `<ns>::data.<Class>.properties.<property>.contexts.<context>.label`;
 *   2. then, ONLY if the column declares no literal of its own, the property's title key,
 *      `<ns>::data.<Class>.properties.<property>.title`.
 *
 * A column whose literal equals its property's `#[Title]` literal counts as declaring none, so it takes the title's
 * translations. A column with its own literal never borrows its property's title: "To" over `$target` is its own word.
 *
 * When every rung misses, the declared label is returned unchanged, and a column with none stays label-less, exactly
 * as projected today. The client then shows the property's title from the schema, which ticket 09 already translates
 * and whose English is the title literal, so the ruled fallback (the column's literal, else the title's, else none)
 * is what a reader sees without changing the wire for every column. laravel-frame never inflects a context label, so
 * there is no English derivation here to keep off translated text.
 */
final class BeamWidgetLabel implements WidgetLabel
{
    public function __construct(private DeclaredText $text) {}

    public function label(ReflectionProperty $property, string $context, ?string $declared): ?string
    {
        $class = $property->getDeclaringClass()->getName();
        $name = $property->getName();
        $titleLiteral = self::titleLiteral($property);
        $ownLiteral = $declared !== null && $declared !== $titleLiteral;

        $keys = [$this->text->key('data', $class, "properties.{$name}.contexts.{$context}.label")];
        if (! $ownLiteral) {
            $keys[] = $this->text->key('data', $class, "properties.{$name}.title");
        }

        return $this->text->resolveFirst($keys, $declared);
    }

    private static function titleLiteral(ReflectionProperty $property): ?string
    {
        $titles = $property->getAttributes(Title::class);

        return $titles === [] ? null : $titles[0]->newInstance()->value;
    }
}
