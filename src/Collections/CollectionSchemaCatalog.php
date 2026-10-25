<?php

namespace Splicewire\Beam\Collections;

use InvalidArgumentException;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Splicewire\Beam\Schema\SchemaId;

/** Lists the current registered Collection schemas; callers apply their resource/actor access gate. */
final class CollectionSchemaCatalog
{
    public function __construct(private SchemaRegistry $registry) {}

    /** @return list<array{stem: string, label: string}> */
    public function addresses(): array
    {
        $latest = [];
        foreach ($this->registry->ids() as $id) {
            try {
                $parsed = SchemaId::from($id);
            } catch (InvalidArgumentException) {
                continue;
            }
            $version = $parsed->version();
            if ($version === null) {
                continue;
            }

            $stem = $parsed->stem();
            if (! isset($latest[$stem]) || $version > $latest[$stem]['version']) {
                $latest[$stem] = ['id' => $id, 'version' => $version];
            }
        }

        ksort($latest);
        $addresses = [];
        foreach ($latest as $stem => $entry) {
            $schema = $this->registry->get($entry['id']);
            if ($schema === null || ! array_key_exists('x-beam-collection', $schema)) {
                continue;
            }

            try {
                $definition = new CollectionDefinition($schema);
            } catch (InvalidArgumentException) {
                continue;
            }

            $addresses[] = ['stem' => $stem, 'label' => $definition->label()];
        }

        return $addresses;
    }
}
