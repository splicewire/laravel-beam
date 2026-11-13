<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Dashboard\RailLeaves;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\DeclaredText;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmRegistry;

/**
 * Four declaration checks ruling 01 assigns to localization 12 (RULING-01, Downstream row for 12):
 *
 * - `localization.unkeyable`: an identity the key grammar cannot spell (a resource key or op name holding a `/`, a
 *   widget context holding a dot, a nav section key holding a `/`), or a rail node with no identity (no `routeName`).
 *   No derived key forms, so the text can never be translated.
 * - `localization.double-default`: one declaration with two declared defaults (one key declared with two English
 *   words, e.g. a nav section seated with two labels). Move the variant into a realm rung.
 * - `localization.column-equals-title`: a column label written equal to its property's `#[Title]`. It declares no word
 *   of its own; remove it and the column takes the title, translations included (ticket 10).
 * - `localization.namespace-collision`: two installed packages that shorten to, or declare, one translator namespace.
 *   Neither gets derived keys until one declares its own `extra.beam.lang-namespace`.
 *
 * Advisory: what is installed and seated is the host's (audits-and-findings guide).
 */
final class LocalizationDeclarationAudit implements DoctorAudit
{
    public const UNKEYABLE = 'localization.unkeyable';

    public const DOUBLE_DEFAULT = 'localization.double-default';

    public const COLUMN_EQUALS_TITLE = 'localization.column-equals-title';

    public const NAMESPACE_COLLISION = 'localization.namespace-collision';

    public const POPULATION = 'PHP-emitted keys only (client keys join with localization 16): the key catalog\'s '
        .'declarations, the nav sections seated here, the rail leaves each registered realm declares, and the installed '
        .'packages\' translator namespaces.';

    public function __construct(
        private KeyCatalog $catalog,
        private TextNamespaces $namespaces,
        private NavSectionRegistry $sections,
        private RealmRegistry $realms,
        private ParticleResourceRegistry $resources,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        return [...$this->unkeyable(), ...$this->doubleDefaults(), ...$this->columnsEqualToTitle(), ...$this->collisions()];
    }

    /** @return list<Finding> */
    private function unkeyable(): array
    {
        $found = [];
        foreach ($this->catalog->unkeyable() as $row) {
            $found[] = Finding::warn(self::UNKEYABLE, sprintf('%s: [%s] is %s, so no key forms and it renders its literal in every language.', $row['where'], $row['identity'], $row['reason']));
        }
        foreach ($this->sections->all() as $section) {
            if (DeclaredText::derive('', 'nav', ['sections', $section->key], 'label') === null) {
                $found[] = Finding::warn(self::UNKEYABLE, sprintf('nav section [%s] (realm %s) has a key the key grammar cannot spell (empty, or holding a /).', $section->key, $section->realm));
            }
        }
        foreach (array_keys($this->realms->all()) as $realm) {
            foreach (RailLeaves::declaredFor($realm, $this->sections, $this->resources)->leaves as $leaf) {
                if ($leaf->routeName === null || $leaf->routeName === '') {
                    $found[] = Finding::warn(self::UNKEYABLE, sprintf('rail leaf "%s" (realm %s, href %s) has no routeName, so it has no identity to key. Give it a route name or an explicit key.', $leaf->title, $realm, $leaf->href));
                }
            }
        }

        return $found !== [] ? $found : [Finding::pass(self::UNKEYABLE, 'Every declared identity can be keyed. '.self::POPULATION)];
    }

    /** @return list<Finding> */
    private function doubleDefaults(): array
    {
        $found = [];
        foreach ($this->catalog->conflicts() as $key => $englishes) {
            // A nav section's two labels are reported below, by seat, with the realm-rung advice.
            if (str_contains($key, 'nav.sections.')) {
                continue;
            }
            $found[] = Finding::warn(self::DOUBLE_DEFAULT, sprintf('[%s] is declared with %d English defaults: %s. One declaration, one word: move the variant into a realm rung.', $key, count($englishes), self::quoteAll(array_keys($englishes))));
        }

        $labels = [];
        foreach ($this->sections->all() as $section) {
            /** @var NavSection $section */
            $labels[$section->key][$section->label][] = $section->realm;
        }
        ksort($labels);
        foreach ($labels as $key => $byLabel) {
            if (count($byLabel) > 1) {
                $found[] = Finding::warn(self::DOUBLE_DEFAULT, sprintf('nav section [%s] is seated with %d labels: %s. Its key carries no realm (ruling 01 §3): keep one word and put a realm\'s variant in that realm\'s rung.', $key, count($byLabel), self::quoteAll(array_map('strval', array_keys($byLabel)))));
            }
        }

        return $found !== [] ? $found : [Finding::pass(self::DOUBLE_DEFAULT, 'Every key has one declared default. '.self::POPULATION)];
    }

    /** @return list<Finding> */
    private function columnsEqualToTitle(): array
    {
        $found = [];
        foreach ($this->catalog->columnsEqualToTitle() as $row) {
            $found[] = Finding::warn(self::COLUMN_EQUALS_TITLE, sprintf('%s::$%s labels its %s column "%s", equal to its #[Title]: remove the label, and the column takes the title (translations included).', $row['class'], $row['property'], $row['context'], $row['label']));
        }

        return $found !== [] ? $found : [Finding::pass(self::COLUMN_EQUALS_TITLE, 'No column label restates its title. '.self::POPULATION)];
    }

    /** @return list<Finding> */
    private function collisions(): array
    {
        $found = [];
        foreach ($this->namespaces->contested() as $namespace => $packages) {
            // Only a collision that costs a FAMILY package its keys (coord ruling (a) scope): two third-party packages
            // that shorten alike (`league/uri`, `opis/uri`) declare no beam text and are none of this check's business.
            $family = array_filter($packages, static fn (string $p): bool => in_array(strtok($p, '/'), TextNamespaces::FAMILY_VENDORS, true));
            if ($family === []) {
                continue;
            }
            $found[] = Finding::warn(self::NAMESPACE_COLLISION, sprintf('The translator namespace [%s] is claimed by %s, so none of them gets derived keys: every declaration renders its literal. Declare `extra.beam.lang-namespace` in one package\'s composer.json.', $namespace, implode(', ', $packages)));
        }

        return $found !== [] ? $found : [Finding::pass(self::NAMESPACE_COLLISION, 'No family package shares a translator namespace with another installed package. '.self::POPULATION)];
    }

    /** @param  list<string>  $values */
    private static function quoteAll(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => '"'.$v.'"', $values));
    }
}
