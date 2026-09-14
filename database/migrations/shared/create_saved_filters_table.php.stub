<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;

/**
 * Shared because saved views belong to both operator and tenant resources. The table name comes
 * from rushing/data-filters' SavedFilter model, so it does not use Beam's configurable prefix.
 * Fresh morph IDs accept integer, UUID and string principals. Existing host-owned UUID/integer
 * columns keep their type: adding the package's persistence surface does not convert owner keys.
 */
return new class extends Migration
{
    private const DEFAULT_INDEX = 'saved_filters_one_default_per_owner_resource';

    public function up(): void
    {
        ConvergentTable::named('saved_filters')->define(function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('resource');
            $table->json('query_parameters');
            $table->string('owner_type')->nullable();
            $this->morphId($table, 'owner_id');
            $table->index(['owner_type', 'owner_id']);
            $table->string('visibility')->default('private');
            $table->string('context_type')->nullable();
            $this->morphId($table, 'context_id');
            $table->index(['context_type', 'context_id']);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['resource', 'owner_type', 'owner_id']);
        })->assert();

        // Preserve the flagship's index name and semantics. Partial indexes are available on these
        // drivers; other drivers retain the shared service's transactional default demotion.
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true) && ! Schema::hasIndex('saved_filters', self::DEFAULT_INDEX)) {
            DB::statement('CREATE UNIQUE INDEX '.self::DEFAULT_INDEX.' ON saved_filters (owner_type, owner_id, resource) WHERE is_default');
        }
    }

    private function morphId(Blueprint $table, string $column): void
    {
        $existing = Schema::hasColumn('saved_filters', $column) ? Schema::getColumnType('saved_filters', $column) : null;
        $definition = match ($existing) {
            'uuid' => $table->uuid($column),
            'bigint', 'int8', 'integer', 'int', 'int4' => $table->bigInteger($column),
            default => $table->string($column),
        };
        $definition->nullable();
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
