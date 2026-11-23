<?php

namespace Splicewire\Beam\Tests\Console;

use App\Models\Orchard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Tests\TestCase;

/**
 * agreements 05: `make:particle-op` and `make:particle-resource` write the `requires:` slot (and its import) ONLY when
 * `--requires` names an Agreement key; without it the emitted class is what it was. The host is relocated to a temp
 * directory (the generator tests' pattern), and every run emits a distinct class name.
 */
class RequiresSlotGeneratorTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = sys_get_temp_dir().'/beam-requires-slot-'.Str::random(10);
        File::ensureDirectoryExists($this->host.'/app/Models');
        File::put($this->host.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_PRETTY_PRINT));
        File::put($this->host.'/app/Models/Orchard.php', "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Orchard extends Model\n{\n    protected \$table = 'orchards';\n}\n");
        $this->app->setBasePath($this->host);

        if (! class_exists(Orchard::class, false)) {
            require_once $this->host.'/app/Models/Orchard.php';
        }

        Schema::create('orchards', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orchards');
        File::deleteDirectory($this->host);

        parent::tearDown();
    }

    public function test_an_op_without_requires_writes_no_slot_and_no_import(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', ['name' => 'PruneOrchard', '--resource' => 'orchards', '--op' => 'prune', '--model' => 'Orchard'])
            ->assertSuccessful();

        $op = File::get($this->host.'/app/Particle/Operations/PruneOrchard.php');
        $this->assertStringNotContainsString('requires:', $op);
        $this->assertStringNotContainsString('Agreements\\Requires', $op);
    }

    public function test_an_op_with_repeated_and_comma_listed_requires_writes_one_slot_with_its_import(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'HarvestOrchard', '--resource' => 'orchards', '--op' => 'harvest', '--model' => 'Orchard',
            '--requires' => ['terms', 'privacy,terms'],
        ])->assertSuccessful();

        $op = File::get($this->host.'/app/Particle/Operations/HarvestOrchard.php');
        $this->assertStringContainsString("    requires: [new Requires('terms'), new Requires('privacy')],\n)]", $op);
        $this->assertStringContainsString("use Splicewire\\Beam\\Agreements\\Requires;\n", $op);
    }

    public function test_task_and_stream_ops_carry_the_slot_too(): void
    {
        foreach (['task' => 'SprayOrchard', 'stream' => 'WatchOrchard'] as $kind => $name) {
            $this->artisan('splicewire:beam:make:particle-op', [
                'name' => $name, '--resource' => 'orchards', '--op' => Str::kebab($name), '--model' => 'Orchard',
                '--kind' => $kind, '--requires' => ['terms'],
            ])->assertSuccessful();

            $op = File::get($this->host."/app/Particle/Operations/{$name}.php");
            $this->assertStringContainsString("    requires: [new Requires('terms')],\n)]", $op, $kind);
            $this->assertStringContainsString("use Splicewire\\Beam\\Agreements\\Requires;\n", $op, $kind);
        }
    }

    public function test_a_resource_with_and_without_requires(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Orchard', '--model' => 'Orchard', '--read-only' => true])
            ->assertSuccessful();
        $plain = File::get($this->host.'/app/Data/OrchardData.php');
        $this->assertStringNotContainsString('requires:', $plain);
        $this->assertStringNotContainsString('Agreements\\Requires', $plain);

        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'GatedOrchard', '--model' => 'Orchard', '--key' => 'gated-orchards', '--read-only' => true,
            '--requires' => ['terms'], '--requires-on' => 'write',
        ])->assertSuccessful();
        $gated = File::get($this->host.'/app/Data/GatedOrchardData.php');
        $this->assertStringContainsString("    requires: [new Requires('terms', on: 'write')],\n)]", $gated);
        $this->assertStringContainsString("use Splicewire\\Beam\\Agreements\\Requires;\n", $gated);

        // agreements 16: a create-only binding is emitted as written.
        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'NewOrchard', '--model' => 'Orchard', '--key' => 'new-orchards', '--read-only' => true,
            '--requires' => ['terms'], '--requires-on' => 'create',
        ])->assertSuccessful();
        $this->assertStringContainsString("    requires: [new Requires('terms', on: 'create')],\n)]", File::get($this->host.'/app/Data/NewOrchardData.php'));

        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'BadOrchard', '--model' => 'Orchard', '--key' => 'bad-orchards', '--requires' => ['terms'], '--requires-on' => 'list',
        ])->expectsOutputToContain('--requires-on must be read, write, both or create.')->assertFailed();
        $this->assertFileDoesNotExist($this->host.'/app/Data/BadOrchardData.php');
    }
}
