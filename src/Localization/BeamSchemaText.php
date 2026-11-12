<?php

namespace Splicewire\Beam\Localization;

use BackedEnum;
use ReflectionClass;
use Schemastud\DataSchemas\Contracts\SchemaText;

/**
 * laravel-data-schemas' display-text hook, bound to beam's ladder (localization ticket 09). The derived keys are
 * ruling 01 §2's:
 *
 * - a class's title and description: `<ns>::data.<Class>.title` / `.description`;
 * - an enum's title: `<ns>::enums.<Enum>.title`;
 * - an enum case's label: `<ns>::enums.<Enum>.cases.<CaseName>.label`.
 *
 * The declared text (`#[Title]`, `#[Description]`, the enum's own `label()`) is the default when the ladder misses, so a
 * host with no lang lines generates exactly what it did. The enum-label half is also what laravel-data-filters reads
 * for a filter's enum options (ticket 14), outside any schema generation.
 */
final class BeamSchemaText implements SchemaText
{
    public function __construct(private DeclaredText $text) {}

    public function classTitle(ReflectionClass $class, ?string $declared): ?string
    {
        return $this->text->for($class->isEnum() ? 'enums' : 'data', $class->getName(), 'title', $declared);
    }

    public function classDescription(ReflectionClass $class, ?string $declared): ?string
    {
        return $this->text->for('data', $class->getName(), 'description', $declared);
    }

    public function enumTitle(string $enumClass, ?string $declared): ?string
    {
        return $this->text->for('enums', $enumClass, 'title', $declared);
    }

    public function enumCaseLabel(BackedEnum $case, string $declared): string
    {
        return (string) $this->text->for('enums', $case::class, 'cases.'.$case->name.'.label', $declared);
    }
}
