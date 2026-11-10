<?php

namespace Splicewire\Beam\Tests\Config;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\ConfigSectionReader;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Tests\Fixtures\Config\VaultSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * A secret declared BELOW a key never leaves through that key (ADR-0245 decision 6; 02 Standards review, MUST-FIX).
 *
 * The case that leaked: a host replaces a map ancestor (`connection`) at the top level, so mergeConfigFrom's shallow
 * merge makes the fold emit `/connection` as ONE leaf whose package layer is the package's whole map — the declared
 * secret `/connection/password` included. The pointer `/connection` is not itself secret, so it went out verbatim.
 *
 * Each ancestor replacement (null, an empty array, a scalar) and the retained map are read on every transport that
 * serves rows — the reader, REST, Frame list and detail, and ConfigSectionAccess (the MCP tool's only read) — and the
 * fake secret must be absent from the whole serialized row on all of them.
 */
class NestedSecretRedactionTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../Fixtures/Config';

    private const FAKE_SECRET = 'FAKE-NESTED-SECRET-7d3';

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('n', 32));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', NestedSecretActor::class);
        $app['config']->set('frame.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('nested_secret_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        NestedSecretActor::create(['name' => 'operator']);
        Gate::define('entitlement:os.operate', fn (?NestedSecretActor $actor): bool => $actor?->name === 'operator');

        $this->app->when(ConfigSectionReader::class)->needs('$configDirectory')->give(realpath(self::FIXTURES.'/host'));
        app(ConfigSectionRegistry::class)->section(VaultSection::class, owner: 'acme/vault', packageFile: realpath(self::FIXTURES.'/package/vault.php'));

        Particle::mount('beam-config')->register();
        $this->actingAs(NestedSecretActor::query()->where('name', 'operator')->firstOrFail());
    }

    /** @return array<string, array{mixed}> */
    public static function ancestorReplacements(): array
    {
        return [
            'host replaces the ancestor with null' => [null],
            'host replaces the ancestor with an empty array' => [[]],
            'host replaces the ancestor with a scalar' => ['disabled'],
        ];
    }

    /** What mergeConfigFrom leaves in config(): the host's top-level `connection` over the package's whole map. */
    private function observe(mixed $hostConnection): void
    {
        config()->set('vault', array_merge(require self::FIXTURES.'/package/vault.php', ['connection' => $hostConnection]));
    }

    /** @return array<string, string> every transport's serialized row(s) */
    private function everyTransport(): array
    {
        $row = app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get('vault'));

        return [
            'reader' => json_encode($row->toArray()),
            'rest' => $this->getJson('/beam-config')->assertOk()->getContent(),
            'frame list' => $this->getJson('/frame/resources/beam-config')->assertOk()->getContent(),
            'frame detail' => $this->getJson('/frame/resources/beam-config/records/vault')->assertOk()->getContent(),
            'access (mcp)' => json_encode(array_map(fn ($r) => $r->toArray(), app(ConfigSectionAccess::class)->rows(auth()->user()))),
        ];
    }

    #[DataProvider('ancestorReplacements')]
    public function test_a_nested_secret_does_not_leave_through_a_replaced_ancestors_layer(mixed $hostConnection): void
    {
        $this->observe($hostConnection);

        foreach ($this->everyTransport() as $transport => $body) {
            $this->assertStringNotContainsString(self::FAKE_SECRET, $body, "{$transport} leaked the nested secret");
        }

        // The ancestor is still described honestly: its package layer keeps the non-secret sibling, and the secret
        // member is present as null rather than dropped.
        $row = app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get('vault'));
        $connection = collect($row->keys)->firstWhere('pointer', '/connection');
        $package = collect($connection->layers)->firstWhere('tier.value', 'package');

        $this->assertSame(['host' => 'db.internal', 'password' => null], $package->value);
        $this->assertTrue($package->shadowed);
        $this->assertSame($hostConnection, $connection->value);
    }

    public function test_the_retained_map_redacts_the_secret_at_its_own_leaf(): void
    {
        config()->set('vault', require self::FIXTURES.'/package/vault.php');

        foreach ($this->everyTransport() as $transport => $body) {
            $this->assertStringNotContainsString(self::FAKE_SECRET, $body, "{$transport} leaked the secret");
        }

        $row = app(ConfigSectionReader::class)->read(app(ConfigSectionRegistry::class)->get('vault'));
        $password = collect($row->keys)->firstWhere('pointer', '/connection/password');
        $this->assertTrue($password->secret);
        $this->assertTrue($password->set);
        $this->assertNull($password->value);
        $this->assertNull($row->values['connection']['password']);
        $this->assertSame('db.internal', $row->values['connection']['host']);
    }
}

class NestedSecretActor extends \Illuminate\Foundation\Auth\User
{
    protected $table = 'nested_secret_actors';

    protected $guarded = [];

    public $timestamps = false;
}
