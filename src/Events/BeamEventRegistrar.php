<?php

namespace Splicewire\Beam\Events;

use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registrars\AttributeRegistrar;
use Rushing\Popcorn\Registries\Registry;

/**
 * Reads `#[BeamEvent]` off the event classes under the configured paths and calls
 * {@see EventTypeRegistry::register()} for each one.
 *
 * Its own class rather than popcorn's {@see AttributeRegistrar}
 * for one reason: that registrar projects ONE entry per scanned class, and `#[BeamEvent]` is repeatable
 * because a single event class may publish under several names (the `{status}` producers are the live
 * shape). A registrar that could only see the first attribute would silently drop the rest — a catalog
 * that is quietly incomplete is worse than one that is empty.
 *
 * Every write carries `$by` = the declaring class's FQCN, which is what makes a duplicate-name rejection
 * name both claimants instead of one.
 */
class BeamEventRegistrar implements Registrar
{
    private AttributedClassScanner $scanner;

    /**
     * @param  list<string>  $paths  directories to scan; non-existent ones are skipped silently
     * @param  list<class-string>  $classes  explicitly named event classes, scanned without a filesystem walk
     */
    public function __construct(
        private array $paths = [],
        private array $classes = [],
        ?AttributedClassScanner $scanner = null,
    ) {
        $this->scanner = $scanner ?? new AttributedClassScanner;
    }

    public function fill(Registry $registry): void
    {
        foreach ($this->eventClasses() as $class) {
            foreach (BeamEvent::on($class) as $declaration) {
                $registry->register($declaration->toEventType(), null, $class);
            }
        }
    }

    public function source(): string
    {
        $where = $this->paths === [] ? 'no configured paths' : implode(', ', $this->paths);

        return "#[BeamEvent] under {$where}";
    }

    /** @return list<class-string> */
    private function eventClasses(): array
    {
        $found = $this->classes;

        if ($this->paths !== []) {
            $found = [...$found, ...$this->scanner->scan($this->paths, BeamEvent::class, false)];
        }

        return array_values(array_unique($found));
    }
}
