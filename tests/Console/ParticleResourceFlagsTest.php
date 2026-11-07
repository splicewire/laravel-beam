<?php

namespace Splicewire\Beam\Tests\Console;

use App\Models\Ghost;
use App\Models\Track;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Rushing\DataFilters\Discovery\AttributedResourceFilterDiscovery;
use Splicewire\Beam\Filters\ResourceFilterDefinition;
use Splicewire\Beam\Nav\NavAudience;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * make-surface ticket 06: `splicewire:beam:make:particle-resource` grows `--filters`, `--cascade-policy` and a
 * `--section` whose unseated case warns. Each feature has a counterexample, so a test that passed by printing
 * everything, or by warning always, would fail: no table means a refusal; a seated section means silence; an
 * authored model is never edited.
 *
 * The host is relocated to a temp directory (the generator tests' pattern), outside the package tree.
 */
class ParticleResourceFlagsTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = sys_get_temp_dir().'/beam-resource-flags-'.Str::random(10);
        File::ensureDirectoryExists($this->host.'/app/Models');
        File::put($this->host.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_PRETTY_PRINT));

        foreach (['Track' => 'tracks', 'Ghost' => 'ghosts'] as $model => $table) {
            File::put($this->host."/app/Models/{$model}.php", "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$model} extends Model\n{\n    protected \$table = '{$table}';\n}\n");
        }

        $this->app->setBasePath($this->host);

        foreach ([Track::class => 'Track', Ghost::class => 'Ghost'] as $class => $file) {
            if (! class_exists($class, false)) {
                require_once $this->host."/app/Models/{$file}.php";
            }
        }

        // The model's ACTUAL table: the generator reads columns from the schema, never from a guess.
        Schema::create('tracks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('title')->nullable();
            $table->string('slug')->unique();
            $table->string('status')->default('draft');
            $table->string('external_ref')->nullable();
            $table->unsignedBigInteger('album_id')->nullable();
            $table->text('body');
            $table->json('meta')->nullable();
            $table->unsignedInteger('plays')->default(0);
            $table->boolean('is_explicit')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tracks');
        File::deleteDirectory($this->host);

        parent::tearDown();
    }

    // ── --filters ───────────────────────────────────────────────────────────────────────────────────

    public function test_filters_emit_a_filter_data_class_from_the_models_actual_columns(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Track', '--filters' => true])
            ->assertSuccessful();

        $source = File::get($this->host.'/app/Data/Filters/TrackFilterData.php');

        // Names search, titles match partially, a unique key sorts, a nullable facet can ask for the empty value.
        $this->assertStringContainsString("#[Filterable(IlikeMatch::class, mode: 'contains')]\n        #[Sortable]\n        public string|Optional|null \$name = null,", $source);
        $this->assertStringContainsString("#[Filterable(Partial::class)]\n        #[Sortable]\n        public string|Optional|null \$title = null,", $source);
        $this->assertStringContainsString("#[Filterable(Exact::class)]\n        #[Sortable]\n        public string|Optional|null \$slug = null,", $source);
        $this->assertStringContainsString("#[Filterable(Exact::class)]\n        public string|Optional|null \$status = null,", $source);
        $this->assertStringContainsString("#[Filterable(NullableExact::class)]\n        public string|Optional|null \$externalRef = null,", $source);
        $this->assertStringContainsString("#[Filterable(Exact::class)]\n        public string|Optional|null \$albumId = null,", $source);
        $this->assertStringContainsString("#[Filterable(Exact::class)]\n        public string|Optional|null \$isExplicit = null,", $source);
        $this->assertStringContainsString("#[Sortable]\n        public string|Optional|int|null \$plays = null,", $source);
        $this->assertStringContainsString("#[Sortable(column: 'released_at')]\n        public string|Optional|null \$releasedAt = null,", $source);
        $this->assertStringContainsString("#[Sortable(column: 'created_at')]\n        public string|Optional|null \$createdAt = null,", $source);
        $this->assertStringContainsString("#[Sortable(column: 'updated_at')]\n        public string|Optional|null \$updatedAt = null,", $source);

        // The primary key, a text body and a json column are not facets.
        $this->assertStringNotContainsString('$id', $source);
        $this->assertStringNotContainsString('$body', $source);
        $this->assertStringNotContainsString('$meta', $source);

        // The filter class names the resource it filters, by the resource's own key.
        $this->assertStringContainsString("#[ResourceFilter(key: 'tracks', query: DeclaredResourceQuery::class)]", $source);
    }

    /**
     * The binding proven by EFFECT, not by text: the consumer's own read resolves the generated class. This test
     * loads the generated classes into the process, so it uses a model no other test generates (`Vinyl`) — a
     * second load of the same class name from another test's temp host would redeclare it.
     */
    public function test_the_emitted_filter_class_becomes_the_resources_filter_vocabulary(): void
    {
        File::put($this->host.'/app/Models/Vinyl.php', "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Vinyl extends Model\n{\n    protected \$table = 'vinyls';\n}\n");
        require_once $this->host.'/app/Models/Vinyl.php';
        Schema::create('vinyls', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Vinyl', '--filters' => true])
            ->expectsOutputToContain("'discover' => ['classes' => [\\App\\Data\\Filters\\VinylFilterData::class]]")
            ->assertSuccessful();

        require_once $this->host.'/app/Data/VinylInputData.php';
        require_once $this->host.'/app/Data/VinylData.php';
        require_once $this->host.'/app/Data/Filters/VinylFilterData.php';

        // The host step the generator prints (listing the class for discovery), then the consumer's own read.
        $this->app->make(AttributedParticleDiscovery::class)->discover(classes: ['App\\Data\\VinylData']);
        $this->app->make(AttributedResourceFilterDiscovery::class)->discover(classes: ['App\\Data\\Filters\\VinylFilterData']);

        $definition = $this->app->make(ResourceFilterDefinition::class)->definition('vinyls');

        $this->assertNotNull($definition);
        $this->assertSame('App\\Data\\Filters\\VinylFilterData', $definition->data);

        Schema::dropIfExists('vinyls');
    }

    public function test_filters_refuse_when_the_models_table_does_not_resolve(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Ghost', '--filters' => true])
            ->expectsOutputToContain('ghosts')
            ->assertFailed();

        // Refused BEFORE anything is written: no filter class, and no half-made resource either.
        $this->assertFileDoesNotExist($this->host.'/app/Data/Filters/GhostFilterData.php');
        $this->assertFileDoesNotExist($this->host.'/app/Data/GhostData.php');
    }

    // ── --cascade-policy ────────────────────────────────────────────────────────────────────────────

    public function test_cascade_policy_prints_the_lines_and_never_edits_the_authored_model(): void
    {
        $before = File::get($this->host.'/app/Models/Track.php');

        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Track', '--cascade-policy' => true])
            ->expectsOutputToContain('use Rushing\PermissionCascade\Attributes\UseCascadePolicy;')
            ->expectsOutputToContain('#[UseCascadePolicy]')
            ->expectsOutputToContain('CascadePolicyRegistrar')
            ->assertSuccessful();

        $this->assertSame($before, File::get($this->host.'/app/Models/Track.php'));
    }

    // ── --section ───────────────────────────────────────────────────────────────────────────────────

    public function test_the_section_is_declared_on_the_resource(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Track', '--section' => 'catalog'])
            ->assertSuccessful();

        $this->assertStringContainsString("    section: 'catalog',\n", File::get($this->host.'/app/Data/TrackData.php'));
    }

    public function test_an_unseated_section_warns_and_prints_a_registration_line_that_lints(): void
    {
        $this->assertSame(0, Artisan::call('splicewire:beam:make:particle-resource', ['name' => 'Track', '--section' => 'catalog']));
        $output = Artisan::output();

        $this->assertStringContainsString('catalog', $output);
        $this->assertStringContainsString('NavSectionRegistry', $output);

        $line = collect(explode("\n", $output))->first(fn (string $l) => str_contains($l, 'NavSectionRegistry::class)->register('));
        $this->assertNotNull($line, $output);

        $file = $this->host.'/registration-line.php';
        File::put($file, "<?php\n\nuse Splicewire\\Beam\\Nav\\NavAudience;\nuse Splicewire\\Beam\\Nav\\NavSection;\nuse Splicewire\\Beam\\Nav\\NavSectionRegistry;\n\n".trim($line)."\n");
        $lint = new Process([PHP_BINARY, '-l', $file], env: ['XDEBUG_MODE' => 'off']);
        $lint->run();
        $this->assertSame(0, $lint->getExitCode(), $lint->getOutput().$lint->getErrorOutput());
    }

    public function test_a_seated_section_does_not_warn(): void
    {
        $this->app->make(NavSectionRegistry::class)->register(new NavSection(
            key: 'catalog', realm: 'tenant', label: 'Catalog', icon: 'Folder', href: '/catalog', order: 50,
            entitlement: null, permission: null, audience: NavAudience::Product,
        ), by: self::class);

        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Track', '--section' => 'catalog'])
            // The marker the printed registration line actually carries (it is fully qualified), so this
            // counterexample cannot pass merely because the wording differs.
            ->doesntExpectOutputToContain('NavSectionRegistry::class)->register(')
            ->doesntExpectOutputToContain('No NavSection seats')
            ->assertSuccessful();
    }
}
