<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Config\ConfigSectionBacking;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Config\GaugesSection;
use Splicewire\Beam\Tests\Fixtures\Config\WidgetsSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * `beam-config` as a transport sees it: discovered from beam's own declaration root, read-only, gated on the
 * operator's existing ability (`entitlement:os.operate`), and serving the same rows over REST and Frame that
 * {@see ConfigSectionReader} produces.
 *
 * Every refusal is paired with the same request admitted for the operator, so a resource that served nothing
 * cannot pass.
 */
class BeamConfigResourceTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('c', 32));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', ConfigReadActor::class);
        $app['config']->set('frame.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('config_read_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        ConfigReadActor::create(['name' => 'operator']);
        ConfigReadActor::create(['name' => 'member']);
        Gate::define('entitlement:os.operate', fn (?ConfigReadActor $actor): bool => $actor?->name === 'operator');

        config()->set('widgets', array_merge(require self::FIXTURES.'/package/widgets.php', require self::FIXTURES.'/host/widgets.php'));
        config()->set('fleet.gauges', require self::FIXTURES.'/host/fleet/gauges.php');
        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));

        app(ConfigSectionRegistry::class)
            ->section(WidgetsSection::class, owner: 'acme/widgets', packageFile: realpath(self::FIXTURES.'/package/widgets.php'))
            ->section(GaugesSection::class, owner: 'host');

        Particle::mount('beam-config')->register();
    }

    private function as(string $name): void
    {
        $this->actingAs(ConfigReadActor::query()->where('name', $name)->firstOrFail());
    }

    /** @return array<string, mixed> each section's row exactly as the one reader produces it */
    private function readerRows(): array
    {
        $rows = [];

        foreach (app(ConfigSectionRegistry::class)->all() as $section) {
            $rows[] = json_decode(json_encode(app(ConfigSectionReader::class)->read($section)->toArray()), true);
        }

        return $rows;
    }

    public function test_beam_declares_the_resource_read_only_on_the_operator_ability(): void
    {
        $resource = app(ParticleResourceRegistry::class)->find('beam-config');

        $this->assertNotNull($resource, 'beam-config is discovered from beam\'s own declaration root');
        $this->assertSame(ConfigSectionBacking::class, $resource->backing);
        $this->assertSame(ConfigSectionData::class, $resource->data);
        $this->assertTrue($resource->readOnly);
        $this->assertSame('entitlement:os.operate', $resource->policy);
        $this->assertNull($resource->publicRead);
    }

    public function test_the_operator_lists_every_section_over_rest_exactly_as_the_reader_reads_it(): void
    {
        $this->as('operator');

        $response = $this->getJson('/beam-config')->assertOk();

        $this->assertSame(['fleet.gauges', 'widgets'], array_column($response->json('data'), 'id'));
        $this->assertSame($this->readerRows(), $response->json('data'));
    }

    public function test_the_operator_reads_the_rows_through_frame_and_one_by_root(): void
    {
        $this->as('operator');

        $list = $this->getJson('/frame/resources/beam-config')->assertOk();
        $this->assertSame(['fleet.gauges', 'widgets'], array_column($list->json('data'), 'id'));
        $this->assertSame($this->readerRows(), $list->json('data'));

        // Frame's detail envelope adds the resolved record's `schemaRef` (null: the row carries its schema inline).
        $one = $this->getJson('/frame/resources/beam-config/records/fleet.gauges')->assertOk()->json('data');
        $this->assertArrayHasKey('schemaRef', $one);
        $this->assertNull($one['schemaRef']);
        unset($one['schemaRef']);
        $this->assertSame($this->readerRows()[0], $one);

        $this->getJson('/frame/resources/beam-config/records/no.such.root')->assertNotFound();
    }

    public function test_a_member_without_the_ability_and_a_guest_are_refused(): void
    {
        $this->as('member');
        $this->getJson('/beam-config')->assertForbidden();
        $this->getJson('/frame/resources/beam-config')->assertForbidden();

        auth()->logout();
        $this->assertContains($this->getJson('/beam-config')->status(), [401, 403]);
    }

    public function test_no_write_verb_is_mounted(): void
    {
        $this->as('operator');

        // A bare mount mounts only the verbs the read-only declaration allows; Frame's write gate refuses the edit.
        $this->postJson('/beam-config', ['root' => 'x'])->assertMethodNotAllowed();
        $this->putJson('/frame/resources/beam-config/records/widgets', ['title' => 'x'])->assertForbidden();
    }
}

class ConfigReadActor extends User
{
    protected $table = 'config_read_actors';

    protected $guarded = [];

    public $timestamps = false;
}
