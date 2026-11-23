<?php

namespace Splicewire\Beam\Config;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * The section audit's reading of a Config Section's DECLARATIONS (config-sections 22, 21, 17): what a section declares
 * that the ladder cannot honour, or honours silently. It walks every property the declaration walk
 * ({@see ConfigSection::keyDeclaration()}) can reach, through {@see ConfigDataTypes}, and asks the walk itself for each
 * key's effective declaration, so the audit and the ladder never disagree on what a key is.
 *
 * Findings, each a pointer (a map entry shows as `*`, a list index as `#`) and a message:
 * - {@see KIND_SECRET_FALSE}: an explicit `secret: false` below a secret ancestor. Secrecy is sticky, so the key stays
 *   secret; the declaration says otherwise and misleads the reader of the section.
 * - {@see KIND_SECRET_IN_LIST}: a secret below a LIST of records (`#[DataCollectionOf]`). A list position is not a stable
 *   identity: no tier may write into a list item, so a record's secret is only ever written with the whole list, and a
 *   re-save that does not resend it loses it (21, option c). The audit FAILS the declaration and names the map form.
 * - {@see KIND_SECRET_OPEN_TO_OPERATOR}: a secret whose ceiling admits the operator tier with no `closed:` either way
 *   (17). A credential the operator may change at runtime should say so (`closed: []`) or close the tier.
 * - {@see KIND_SECRET_UNREACHED}: a property the walk does not descend (an interface, a non-Data class, or an abstract
 *   Data type) where a class it could hold declares a secret the walk cannot see, so that secret would be stored in
 *   plain. The candidates are the classes LOADED when the audit runs (`get_declared_classes()`): an implementation the
 *   autoloader has not loaded yet is not found. An untyped property, or an array with no item attribute, has no class to
 *   follow and is not reported.
 */
final class ConfigSectionDeclarations
{
    public const KIND_SECRET_FALSE = 'secret-false-below-secret';

    public const KIND_SECRET_IN_LIST = 'secret-in-list';

    public const KIND_SECRET_OPEN_TO_OPERATOR = 'secret-open-to-operator';

    public const KIND_SECRET_UNREACHED = 'secret-unreached';

    /**
     * @param  class-string<ConfigSection>  $section
     * @return list<array{kind: string, pointer: string, message: string}>
     */
    public static function findings(string $section): array
    {
        $findings = [];
        $entries = $section::declaration()->entries;
        $start = $entries !== null ? ['*'] : [];

        self::walk($section, $section::keyClass(), $start, $start, [], $findings);

        return $findings;
    }

    /**
     * @param  class-string<ConfigSection>  $section
     * @param  class-string  $class
     * @param  list<string>  $segments  sample segments for the walk (a map entry `entry`, a list index `0`)
     * @param  list<string>  $shown  the same path as shown (`*`, `#`)
     * @param  array<string, true>  $path  classes on the current path, each with the context it was entered in (a
     *                                       self-referential type ends there)
     * @param  list<array{kind: string, pointer: string, message: string}>  $findings
     */
    private static function walk(string $section, string $class, array $segments, array $shown, array $path, array &$findings): void
    {
        if (! class_exists($class)) {
            return;
        }

        // A type that recurses is read again once per CONTEXT its findings depend on (inside a list or not, below a
        // secret or not), so a recursive list of records holding a secret is still reported (Codex review, lane A). There
        // are four contexts per class, so the walk still ends.
        $sample = self::sample($segments);
        $context = $class.'|'.(int) $section::throughListIndex($sample).'|'.(int) $section::keyDeclaration($sample)->secret;

        if (isset($path[$context])) {
            return;
        }

        $path[$context] = true;

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $name = $property->getName();
            $here = [...$segments, $name];
            $shownHere = [...$shown, $name];
            $pointer = '/'.implode('/', array_map(fn (string $s): string => str_replace(['~', '/'], ['~0', '~1'], $s), $shownHere));
            $parent = $section::keyDeclaration(self::sample($segments));
            $declared = $section::keyDeclaration(self::sample($here));
            $attribute = $property->getAttributes(ConfigKey::class)[0] ?? null;

            if ($attribute !== null && $parent->secret && $segments !== [] && self::explicitlyNotSecret($attribute->getArguments())) {
                $findings[] = ['kind' => self::KIND_SECRET_FALSE, 'pointer' => $pointer, 'message' => "{$pointer} declares secret: false below a secret ancestor; secrecy is sticky, so it stays secret. Drop the argument."];
            }

            $own = $attribute?->newInstance();

            // Reported where the secret is declared, once (not again at every key below it).
            if ($own !== null && $own->secret && ! $parent->secret) {
                if ($section::throughListIndex(self::sample($here))) {
                    $findings[] = ['kind' => self::KIND_SECRET_IN_LIST, 'pointer' => $pointer, 'message' => "{$pointer} is a secret inside a list of records; a list position is not a stable identity, so no override can address one record's secret and a re-save that does not resend it loses it. Declare the list as a map keyed by a stable name (#[MapValues(Entry::class)])."];
                } elseif ($declared->ceiling->admits(ConfigTier::Operator) && $declared->closed === null) {
                    $findings[] = ['kind' => self::KIND_SECRET_OPEN_TO_OPERATOR, 'pointer' => $pointer, 'message' => "{$pointer} is a secret the operator tier may override at runtime, and its declaration does not say so: declare closed: [ConfigTier::Operator] to keep the operator out, or closed: [] to allow it."];
                }
            }

            $nested = ConfigDataTypes::nested($property);

            foreach ($nested as $data) {
                self::walk($section, $data, $here, $shownHere, $path, $findings);
            }

            if ($nested !== []) {
                foreach ($nested as $data) {
                    if ((new ReflectionClass($data))->isAbstract()) {
                        self::unreached($pointer, $data, $declared->secret, $findings);
                    }
                }

                continue;
            }

            if (($entry = ConfigDataTypes::mapEntry($property)) !== null) {
                self::walk($section, $entry, [...$here, 'entry'], [...$shownHere, '*'], $path, $findings);

                continue;
            }

            if (($entry = ConfigDataTypes::listEntry($property)) !== null) {
                self::walk($section, $entry, [...$here, '0'], [...$shownHere, '#'], $path, $findings);

                continue;
            }

            foreach (self::classTypes($property) as $type) {
                self::unreached($pointer, $type, $declared->secret, $findings);
            }
        }
    }

    /**
     * Report `$pointer` when a class it could hold (an implementation or subclass of `$type`, loaded now) declares a
     * secret `$type` itself does not. A key already secret at `$pointer` is split whole, so nothing below it is plain.
     *
     * @param  class-string  $type
     * @param  list<array{kind: string, pointer: string, message: string}>  $findings
     */
    private static function unreached(string $pointer, string $type, bool $secretHere, array &$findings): void
    {
        if ($secretHere) {
            return;
        }

        foreach (get_declared_classes() as $candidate) {
            if (! is_subclass_of($candidate, $type) || ! is_subclass_of($candidate, Data::class)) {
                continue;
            }

            $reflection = new ReflectionClass($candidate);

            if ($reflection->isAbstract()) {
                continue;
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $own = ($property->getAttributes(ConfigKey::class)[0] ?? null)?->newInstance();

                if ($own !== null && $own->secret && ! self::declaredSecretOn($type, $property->getName())) {
                    $findings[] = ['kind' => self::KIND_SECRET_UNREACHED, 'pointer' => $pointer, 'message' => "{$pointer} can hold {$candidate}, whose secret {$property->getName()} the declaration walk cannot reach (it reads the declared type {$type}), so it would be stored in plain. Declare {$pointer} secret, or type it as the concrete Data class."];

                    return;
                }
            }
        }
    }

    /**
     * Whether `$class` itself (or an ancestor of it) declares `$name` SECRET: the walk reads the declared type, so a
     * subclass that re-declares an inherited plain property as secret is still unreached (Codex review, lane A).
     */
    private static function declaredSecretOn(string $class, string $name): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $reflection = new ReflectionClass($class);

        if (! $reflection->hasProperty($name)) {
            return false;
        }

        $own = ($reflection->getProperty($name)->getAttributes(ConfigKey::class)[0] ?? null)?->newInstance();

        return $own !== null && $own->secret;
    }

    /**
     * The non-builtin, non-Data class or interface types a property names (what the declaration walk does not descend).
     *
     * @return list<class-string>
     */
    private static function classTypes(ReflectionProperty $property): array
    {
        $type = $property->getType();
        $members = $type instanceof ReflectionUnionType ? $type->getTypes() : ($type === null ? [] : [$type]);
        $classes = [];

        foreach ($members as $member) {
            if (! $member instanceof ReflectionNamedType || $member->isBuiltin()) {
                continue;
            }

            $name = $member->getName();

            if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
                continue;
            }

            if (interface_exists($name) || (class_exists($name) && ! is_subclass_of($name, Data::class))) {
                $classes[] = $name;
            }
        }

        return $classes;
    }

    /** @param array<array-key, mixed> $arguments a `#[ConfigKey]`'s arguments, named or positional (secret is the 3rd) */
    private static function explicitlyNotSecret(array $arguments): bool
    {
        return (array_key_exists('secret', $arguments) && $arguments['secret'] === false)
            || (array_key_exists(2, $arguments) && $arguments[2] === false);
    }

    /**
     * The walk's segments, as the ladder reads them: a map section's leading `*` is any entry name.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private static function sample(array $segments): array
    {
        return array_map(fn (string $s): string => $s === '*' ? 'entry' : $s, $segments);
    }
}
