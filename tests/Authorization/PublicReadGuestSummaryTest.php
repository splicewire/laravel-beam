<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/**
 * ADR-0230 Public Read on the Frame summary path (EP-03 clause 2; EP-JOIN item 34). The summary aggregates over the
 * resource's population, so a guest without a declared `publicRead:` must be refused BEFORE anything is counted —
 * a count is itself a disclosure. {@see PublicReadGuestReadTest} covers the list, detail and edit paths; this is the
 * summary/aggregate one it does not reach.
 *
 * The frame mount admits guests here on purpose (`web` only): with `auth` in front, a guest would be refused by the
 * middleware and the Public Read rule would never be exercised.
 */
class PublicReadGuestSummaryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('g', 32));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('frame.middleware', ['web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });

        Gadget::create(['user_id' => 1]);
        Gadget::create(['user_id' => 2]);
    }

    private function declare(?string $publicRead): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'gadgets',
            backing: Gadget::class,
            data: GadgetData::class,
            publicRead: $publicRead,
            project: fn (Gadget $gadget): GadgetData => new GadgetData((string) $gadget->getKey()),
            readOnly: true,
            label: 'Gadgets',
        ));
    }

    public function test_a_guest_is_refused_the_summary_without_a_public_read_before_anything_is_counted(): void
    {
        $this->declare(publicRead: null);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->getJson('/frame/resources/gadgets/summary')->assertForbidden();

        $counts = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query): bool => str_contains(strtolower($query), 'count('));
        $this->assertSame([], array_values($counts));
    }

    public function test_a_guest_is_admitted_to_the_summary_through_a_public_read(): void
    {
        $this->declare(publicRead: GadgetData::class);

        $this->getJson('/frame/resources/gadgets/summary')->assertOk();
    }
}
