<?php

namespace Splicewire\Beam\Localization\Catalog;

use BackedEnum;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionEnum;
use ReflectionProperty;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;
use Schemastud\Frame\Attributes\WidgetIn;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\DeclaredText;
use Splicewire\Beam\Localization\FrameText;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Schema\IdentifierLabels;
use Throwable;

/**
 * Every key a host can emit, with its English (localization 12; story 24). Read from the BOOTED host:
 *
 * - **derived**, from declarations: each framed resource's `label` and `singular`; each action affordance's `label`;
 *   every Data class and enum reachable from a particle (a class's `title` and `description`, each property's `title`,
 *   `description` and per-context `label`, an enum's `title` and case labels); and the coded-error enums family source
 *   declares (each case's `message`);
 * - **explicit**, from files: every line in a family package's `lang/en/*.php` and the host's `lang/en/*.php`, in the
 *   groups that are not derived kinds.
 *
 * The English is what renders when no line exists: the declaration's literal, or its English derivation.
 */
final class KeyCatalog
{
    /** What every command and audit over this catalog read (ticket 12, done-when: each says its population). */
    public const POPULATION = 'PHP-emitted keys only: framed resources and their actions, Data classes and enums reachable '
        .'from a particle, coded-error enums in family source, and the explicit keys in family and host lang/en files. '
        .'Client keys join with localization 16; nav and group names with localization 11.';

    /** The groups whose keys are derived from declarations, never written by hand (ADR-0244). */
    public const DERIVED_GROUPS = ['resources', 'data', 'enums', 'actions', 'nav', 'groups'];

    /** @var list<CatalogEntry>|null */
    private ?array $entries = null;

    /** @var list<array{identity: string, where: string, reason: string}> */
    private array $unkeyable = [];

    /** @var array<string, array<string, string>> */
    private array $conflicts = [];

    /** @var list<array{class: string, property: string, context: string, label: string}> */
    private array $columnEqualsTitle = [];

    public function __construct(
        private ParticleResourceRegistry $resources,
        private ParticleOperationRegistry $operations,
        private IdentifierLabels $reachable,
        private TextNamespaces $namespaces,
        private FrameText $frameText,
        private string $hostLangPath,
    ) {}

    /** @return list<CatalogEntry> sorted by key, one entry per key */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $this->unkeyable = [];
        $this->columnEqualsTitle = [];
        $byKey = [];
        $defaults = [];
        foreach ([...$this->resourceEntries(), ...$this->schemaEntries(), ...$this->codedErrorEntries(), ...$this->explicitEntries()] as $entry) {
            $byKey[$entry->key] ??= $entry;
            $defaults[$entry->key][$entry->english] = $entry->origin;
        }
        ksort($byKey);
        $this->conflicts = array_filter($defaults, static fn (array $englishes): bool => count($englishes) > 1);
        ksort($this->conflicts);

        return $this->entries = array_values($byKey);
    }

    /**
     * Identities the key grammar cannot spell (ruling 01 §2): no derived key forms, so the literal renders and the text
     * can never be translated. `localization.unkeyable` reports them.
     *
     * @return list<array{identity: string, where: string, reason: string}>
     */
    public function unkeyable(): array
    {
        $this->entries();

        return $this->unkeyable;
    }

    /**
     * Keys declared with more than one English default (one declaration registered twice with two words).
     *
     * @return array<string, array<string, string>> key => [english => origin]
     */
    public function conflicts(): array
    {
        $this->entries();

        return $this->conflicts;
    }

    /**
     * Column labels written equal to their property's `#[Title]`: they declare no word of their own (ticket 10 reads
     * them as no label) and should be removed so the column takes the title, translations included.
     *
     * @return list<array{class: string, property: string, context: string, label: string}>
     */
    public function columnsEqualToTitle(): array
    {
        $this->entries();

        return $this->columnEqualsTitle;
    }

    /** @return array<string, CatalogEntry> by key */
    public function byKey(): array
    {
        $map = [];
        foreach ($this->entries() as $entry) {
            $map[$entry->key] = $entry;
        }

        return $map;
    }

    /** @return list<CatalogEntry> */
    private function resourceEntries(): array
    {
        $entries = [];
        foreach ($this->resources->all() as $resource) {
            if (! $resource->isFramed()) {
                continue;
            }
            $prefix = $this->frameText->prefixFor($this->resources->registrantOf($resource->key), is_string($resource->data) ? $resource->data : null);
            $label = $resource->label !== '' ? $resource->label : Str::headline($resource->key);
            $singular = $resource->singularLabel !== '' ? $resource->singularLabel : Str::singular($label);
            $origin = 'resource '.$resource->key;

            if ($prefix !== null && DeclaredText::derive($prefix, 'resources', [$resource->key], 'label') === null) {
                $this->unkeyable[] = ['identity' => $resource->key, 'where' => $origin, 'reason' => 'a resource key the key grammar cannot spell (empty, or holding a /)'];
            }
            $this->add($entries, DeclaredText::derive($prefix, 'resources', [$resource->key], 'label'), $label, $origin, $resource->label === '');
            $this->add($entries, DeclaredText::derive($prefix, 'resources', [$resource->key], 'singular'), $singular, $origin, $resource->singularLabel === '');

            foreach ($this->operations->forResource($resource->key) as $operation) {
                $affordance = $operation->actionAffordance();
                if ($affordance === null) {
                    continue;
                }
                $owner = $this->frameText->prefixFor($this->operations->registrantOf($resource->key.'.'.$operation->name), packageRegistrant: true);
                if ($owner !== null && DeclaredText::derive($owner, 'actions', [$resource->key, $operation->name], 'label') === null) {
                    $this->unkeyable[] = ['identity' => $operation->name, 'where' => 'action '.$resource->key.'.'.$operation->name, 'reason' => 'an operation name the key grammar cannot spell (empty, or holding a /)'];
                }
                $this->add(
                    $entries,
                    DeclaredText::derive($owner, 'actions', [$resource->key, $operation->name], 'label'),
                    $affordance->label ?? Str::headline($operation->name),
                    'action '.$resource->key.'.'.$operation->name,
                    $affordance->label === null,
                );
            }
        }

        return $entries;
    }

    /** @return list<CatalogEntry> */
    private function schemaEntries(): array
    {
        $entries = [];
        $identifierTitles = (bool) config('data-schemas.schema_metadata.identifier_titles', true);

        foreach ($this->reachable->reachable() as $class => $kind) {
            $reflection = new ReflectionClass($class);
            $kindGroup = $kind === 'enum' ? 'enums' : 'data';
            $key = fn (string $item): ?string => $this->derivedKey($kindGroup, $class, $item);
            $title = self::attribute($reflection, Title::class);

            if ($title !== null || $identifierTitles) {
                $this->add($entries, $key('title'), $title ?? $reflection->getShortName(), $class, $title === null);
            }

            if ($kind === 'enum') {
                if (enum_exists($class)) {
                    foreach ((new ReflectionEnum($class))->getCases() as $case) {
                        $value = $case->getValue();
                        if ($value instanceof ProvidesEnumLabel) {
                            $this->add($entries, $key('cases.'.$case->getName().'.label'), $value->label(), $class);
                        }
                    }
                }

                continue;
            }

            if (($description = self::attribute($reflection, Description::class)) !== null) {
                $this->add($entries, $key('description'), $description, $class);
            }

            // Every public property the class exposes, INHERITED ones included (REVIEW-B6 B3), each keyed by its
            // DECLARING class: that is the identity the emit hooks use (TranslatedPropertyTextStrategy, BeamWidgetLabel),
            // so a resource exposing only a child still lists its parent's keys. Two children of one parent list the
            // parent's key once (entries() keeps one entry per key).
            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->isStatic()) {
                    continue;
                }
                $declaring = $property->getDeclaringClass()->getName();
                $propertyKey = fn (string $item): ?string => $this->derivedKey('data', $declaring, $item);
                $name = $property->getName();
                $where = $declaring.'::$'.$name;
                foreach ([Title::class => 'title', Description::class => 'description'] as $attribute => $slot) {
                    if (($text = self::attribute($property, $attribute)) !== null) {
                        $this->add($entries, $propertyKey("properties.{$name}.{$slot}"), $text, $where);
                    }
                }
                $propertyTitle = self::attribute($property, Title::class);
                foreach ($property->getAttributes(WidgetIn::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    $widget = $attribute->newInstance();
                    // A context is one key segment: a dot in it cannot be spelled (ruling 01 §2; no escaping exists).
                    if ($widget->context === '' || str_contains($widget->context, '.') || str_contains($widget->context, '/')) {
                        $this->unkeyable[] = ['identity' => $widget->context, 'where' => $where, 'reason' => 'a widget context the key grammar cannot spell (empty, or holding a . or /)'];

                        continue;
                    }
                    $label = is_string($widget->label) && $widget->label !== '' ? $widget->label : null;
                    if ($label !== null && $propertyTitle !== null && $label === $propertyTitle) {
                        $this->columnEqualsTitle[] = ['class' => $declaring, 'property' => $name, 'context' => $widget->context, 'label' => $label];
                    }
                    // A LABEL-LESS context still has a key: the widget-label hook tries it first (ticket 10's chain). Its
                    // English is what renders without a line: the property's title, else the headlined name the client
                    // falls back to (an English derivation, a default only); never an invented declaration.
                    $this->add(
                        $entries,
                        $propertyKey("properties.{$name}.contexts.{$widget->context}.label"),
                        $label ?? $propertyTitle ?? Str::headline($name),
                        $where,
                        $label === null && $propertyTitle === null,
                    );
                }
            }
        }

        return $entries;
    }

    /** @return list<CatalogEntry> */
    private function codedErrorEntries(): array
    {
        $entries = [];
        foreach ($this->namespaces->familyPackages() as $package) {
            foreach (SourceScanner::files([$package['root'].'/src']) as $file) {
                foreach (SourceScanner::enums($file) as $enum) {
                    try {
                        if (! enum_exists($enum) || ! is_subclass_of($enum, CodedError::class) || ! is_subclass_of($enum, BackedEnum::class)) {
                            continue;
                        }
                    } catch (Throwable) {
                        continue;
                    }
                    foreach ($enum::cases() as $case) {
                        /** @var CodedError $case */
                        $this->add($entries, $case->translationKey(), $case->defaultMessage(), $enum);
                    }
                }
            }
        }

        return $entries;
    }

    /** @return list<CatalogEntry> */
    private function explicitEntries(): array
    {
        $entries = [];
        $sources = [];
        foreach ($this->namespaces->familyPackages() as $name => $package) {
            // Hint the namespace (a lookup of an unhinted namespace loads nothing); a refused one has no keys.
            if ($this->namespaces->ownerOfPackage($name)->keyPrefix() === null) {
                continue;
            }
            $sources[] = [$package['namespace'].'::', $package['lang'].'/en'];
        }
        $sources[] = ['', rtrim($this->hostLangPath, '/').'/en'];

        foreach ($sources as [$prefix, $dir]) {
            foreach (SourceScanner::files([$dir]) as $file) {
                if (dirname($file) !== $dir) {
                    continue; // a realm rung (en/<realm>/…) is never a base key
                }
                $group = basename($file, '.php');
                if (in_array($group, self::DERIVED_GROUPS, true)) {
                    continue;
                }
                $lines = @include $file;
                foreach (self::flatten(is_array($lines) ? $lines : []) as $item => $text) {
                    $entries[] = new CatalogEntry($prefix.$group.'.'.$item, $text, 'explicit', $file);
                }
            }
        }

        return $entries;
    }

    private function derivedKey(string $kind, string $class, string $item): ?string
    {
        $prefix = $this->namespaces->ownerOfClass($class)->keyPrefix();

        return $prefix === null ? null : $prefix.$kind.'.'.str_replace('\\', '/', ltrim($class, '\\')).'.'.$item;
    }

    /** @param  list<CatalogEntry>  $entries */
    private function add(array &$entries, ?string $key, string $english, string $origin, bool $derivedEnglish = false): void
    {
        if ($key !== null) {
            $entries[] = new CatalogEntry($key, $english, 'derived', $origin, $derivedEnglish);
        }
    }

    /** @param  ReflectionClass<object>|ReflectionProperty  $target */
    private static function attribute(ReflectionClass|ReflectionProperty $target, string $attribute): ?string
    {
        $found = $target->getAttributes($attribute);
        $instance = $found === [] ? null : $found[0]->newInstance();

        return $instance instanceof Title || $instance instanceof Description ? $instance->value : null;
    }

    /**
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string> dotted item => line
     */
    public static function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];
        foreach ($lines as $key => $value) {
            $item = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += self::flatten($value, $item);
            } elseif (is_string($value)) {
                $flat[$item] = $value;
            }
        }

        return $flat;
    }
}
