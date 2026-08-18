<?php

namespace Splicewire\Beam\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;

/**
 * A Blueprint used as a READER rather than a writer: {@see ConvergentTable} runs the migration's own
 * definition closure against one of these to find out what the table is supposed to look like, then
 * throws it away. Nothing here ever reaches the database — the closure only appends to the blueprint,
 * and this class never compiles or builds it.
 *
 * It exists for one reason. Laravel turns a FLUENT index (`$table->string('form_key')->index()`) into an
 * index command inside `addFluentIndexes()`, which normally runs during `toSql()` — so a blueprint that
 * is never compiled reports the standalone `$table->unique([...])` calls and silently omits every inline
 * one. That method is `protected`, so reaching it means subclassing.
 *
 * **Order is load-bearing:** `addFluentIndexes()` NULLS the fluent attribute on each column as it lifts
 * it into a command, so {@see declaredIndexes()} must be called AFTER the caller has finished with
 * {@see Blueprint::getColumns()}. That ordering is deliberate rather than incidental — a missing column
 * is re-added with its attributes intact, fluent index included, so Laravel creates that index as part
 * of the ALTER and this pass only has to cover indexes on columns that already existed.
 */
class DeclaredShape extends Blueprint
{
    /**
     * Every index the definition declares — standalone AND fluent — as index commands.
     *
     * ColumnDefinitions are filtered out because a non-creating blueprint files them as commands too,
     * and a column named `index` would otherwise read as one.
     *
     * @return list<Fluent>
     */
    public function declaredIndexes(): array
    {
        $this->addFluentIndexes();

        return array_values(array_filter(
            $this->getCommands(),
            fn ($command) => ! $command instanceof ColumnDefinition,
        ));
    }
}
