<?php

namespace Splicewire\Beam\Tests\Collections;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Collections\StandaloneRecordExclusion;
use Splicewire\Beam\Collections\StandaloneRecordQuery;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\TestCase;

class StandaloneRecordsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create(Beam::table('particles'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_ref');
            $table->string('source_tier');
        });
    }

    private function record(string $tier): string
    {
        $id = (string) Str::uuid7();
        DB::table(Beam::table('particles'))->insert([
            'id' => $id, 'schema_ref' => 'https://example.test/schemas/article/1', 'source_tier' => $tier,
        ]);

        return $id;
    }

    public function test_the_default_port_excludes_both_shadow_tiers_without_any_contributor(): void
    {
        $local = $this->record('local');
        $this->record('shadow-cached');
        $this->record('shadow-pinned');
        $this->assertSame([$local], app(StandaloneRecordQuery::class)->query()->pluck('id')->all());
    }

    public function test_an_exclusion_contributor_only_narrows_the_local_population(): void
    {
        $kept = $this->record('local');
        $body = $this->record('local');
        $this->record('shadow-cached');
        $contributor = new class($body) implements StandaloneRecordExclusion
        {
            public function __construct(private string $body) {}

            public function excludeFrom(Builder $query): void
            {
                $query->whereKeyNot($this->body);
            }
        };
        $this->app->instance('test.entry-body-exclusion', $contributor);
        $this->app->tag('test.entry-body-exclusion', StandaloneRecordExclusion::class);

        $this->assertSame([$kept], app(StandaloneRecordQuery::class)->query()->pluck('id')->all());
    }
}
