<?php

declare(strict_types=1);

namespace Splicewire\Beam;

/**
 * The Beam facade-lite: the ONE place the table-prefix idiom lives (beam-particle-rename ticket 01).
 *
 * Every Beam table name is `config('beam.core.table_prefix') . $name`, resolved HERE and nowhere else —
 * so a retrofit host (Beam dropped into a pre-existing Laravel app that already owns `posts`/`teams`)
 * changes one config value and every Beam model `getTable()`, migration, and `->constrained(...)` follows.
 * Asserting prefix application at this single seam (not per-model) is the whole point of centralizing it.
 *
 * Additive in ticket 01: the helper + the config knob exist, but no model/migration has been routed
 * through it yet (that is T03/T04, in batches). Default prefix `beam_`; a retrofit host may set `''`.
 */
final class Beam
{
    /**
     * The prefixed table name for a bare Beam table stem — `beam_particles`, `beam_schemas`, … under the
     * default, or `<host_prefix>particles` when a retrofit host overrides `beam.core.table_prefix`.
     */
    public static function table(string $name): string
    {
        return self::tablePrefix().$name;
    }

    /** The configured Beam table prefix (default `beam_`). */
    public static function tablePrefix(): string
    {
        return (string) config('beam.core.table_prefix', 'beam_');
    }
}
