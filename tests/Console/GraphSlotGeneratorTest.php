<?php

namespace Splicewire\Beam\Tests\Console;

use App\Models\Grove;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Tests\TestCase;

/**
 * graph-operations 04, scope 5: `splicewire:beam:make:particle-resource --graph=` writes the `graph:` slot, and its
 * own check covers it. The check passes on what it wrote, and FAILS when the named column is gone from the table.
 *
 * The host is relocated to a temp directory (the generator tests' pattern). Each run loads its emitted class into
 * the process, so the two runs emit two different class names over the same model.
 */
class GraphSlotGeneratorTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = sys_get_temp_dir().'/beam-graph-slot-'.Str::random(10);
        File::ensureDirectoryExists($this->host.'/app/Models');
        File::put($this->host.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_PRETTY_PRINT));
        File::put($this->host.'/app/Models/Grove.php', "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Grove extends Model\n{\n    protected \$table = 'groves';\n}\n");
        $this->app->setBasePath($this->host);

        if (! class_exists(Grove::class, false)) {
            require_once $this->host.'/app/Models/Grove.php';
        }

        Schema::create('groves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('groves');
        File::deleteDirectory($this->host);

        parent::tearDown();
    }

    public function test_it_writes_the_slot_its_check_passes_and_the_check_fails_once_the_column_is_removed(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'Grove', '--model' => 'Grove', '--read-only' => true,
            '--graph' => 'parent:parent_id', '--graph-label' => 'name',
        ])->assertSuccessful();

        $this->assertStringContainsString(
            "    graph: new \\Splicewire\\Beam\\Graph\\Edges\\ParentEdge('parent_id', label: 'name'),\n",
            File::get($this->host.'/app/Data/GroveData.php'),
        );

        Schema::table('groves', fn (Blueprint $table) => $table->dropColumn('parent_id'));

        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'GroveCopy', '--model' => 'Grove', '--key' => 'grove-copies', '--read-only' => true,
            '--graph' => 'parent:parent_id',
        ])->expectsOutputToContain('`groves.parent_id`')->assertFailed();
    }

    public function test_an_edge_table_slot_is_written_in_full(): void
    {
        Schema::create('grove_links', function (Blueprint $table) {
            $table->unsignedBigInteger('from_id');
            $table->unsignedBigInteger('to_id');
            $table->string('kind')->nullable();
        });

        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'LinkedGrove', '--model' => 'Grove', '--key' => 'linked-groves', '--read-only' => true,
            '--graph' => 'edges:grove_links,from_id,to_id,kind',
        ])->assertSuccessful();

        $this->assertStringContainsString(
            "    graph: new \\Splicewire\\Beam\\Graph\\Edges\\EdgeTable('grove_links', from: 'from_id', to: 'to_id', type: 'kind'),\n",
            File::get($this->host.'/app/Data/LinkedGroveData.php'),
        );

        Schema::dropIfExists('grove_links');
    }

    public function test_graph_needs_a_model_and_a_well_formed_value(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Owned', '--graph' => 'parent:parent_id'])
            ->expectsOutputToContain('select the model with --model')->assertFailed();

        foreach (['parent_id', 'parent:', 'edges:links,from_id', 'tree:parent_id'] as $value) {
            $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Malformed', '--model' => 'Grove', '--graph' => $value])
                ->expectsOutputToContain('--graph must be')->assertFailed();
        }

        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Labelled', '--model' => 'Grove', '--graph-label' => 'name'])
            ->expectsOutputToContain('add --graph')->assertFailed();

        $this->assertFileDoesNotExist($this->host.'/app/Data/OwnedData.php');
        $this->assertFileDoesNotExist($this->host.'/app/Data/MalformedData.php');
    }
}
