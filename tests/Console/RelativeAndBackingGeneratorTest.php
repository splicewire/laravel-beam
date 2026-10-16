<?php

namespace Splicewire\Beam\Tests\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Splicewire\Beam\Tests\TestCase;

/**
 * The two newest scaffolders (scaffold-family ticket 01), tested in ParticleGeneratorTest's
 * throwaway-host pattern: generate into a fresh base path, assert the emitted DECLARATION (slots
 * present and filled — "the slot is always present and the property is the placeholder"), and prove
 * the emitted PHP is real code by linting and reflecting it.
 */
class RelativeAndBackingGeneratorTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = sys_get_temp_dir().'/beam-relgen-'.Str::random(10);
        File::ensureDirectoryExists($this->host.'/app/Models');
        File::put($this->host.'/composer.json', json_encode(
            ['autoload' => ['psr-4' => ['App\\' => 'app/']]],
            JSON_PRETTY_PRINT,
        ));
        File::put($this->host.'/app/Models/Verse.php', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        class Verse extends Model {}
        PHP);
        $this->app->setBasePath($this->host);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->host);

        parent::tearDown();
    }

    private function read(string $relative): string
    {
        $path = $this->host.'/'.$relative;
        $this->assertFileExists($path);

        return File::get($path);
    }

    public function test_the_relative_generator_emits_every_slot_and_requires_a_parent(): void
    {
        // A relative is a fact about its parent: no --of, no file.
        $this->artisan('splicewire:beam:make:particle-relative', ['name' => 'VerseRelative'])
            ->assertFailed();

        $this->artisan('splicewire:beam:make:particle-relative', [
            'name' => 'VerseRelative',
            '--of' => 'lyric',
        ])->assertSuccessful();

        $emitted = $this->read('app/Particles/Relatives/VerseRelative.php');

        $this->assertStringContainsString("child: 'verses',", $emitted);
        $this->assertStringContainsString("of: 'lyric',", $emitted);
        $this->assertStringContainsString('model: Verse::class,', $emitted);
        // Derived but EMITTED: the relationship method is a decision.
        $this->assertStringContainsString("via: 'verses',", $emitted);
        // Both mount spellings travel in the docblock.
        $this->assertStringContainsString("->relatives([VerseRelative::class])", $emitted);
        $this->assertStringContainsString('ParticleMountManager::relatives()', $emitted);
    }

    public function test_the_backing_generator_assembles_exactly_the_requested_capabilities(): void
    {
        $this->artisan('splicewire:beam:make:particle-backing', [
            'name' => 'VerseBacking',
            '--model' => 'Verse',
            '--writes' => true,
        ])->assertSuccessful();

        $emitted = $this->read('app/Particles/Backings/VerseBacking.php');

        // Marker + default read + the two asked-for capabilities, and nothing else.
        $this->assertStringContainsString('implements BacksModel, QueriesRecords, ResourceBacking, WritesRecords', $emitted);
        $this->assertStringContainsString('public function query(array $filters)', $emitted);
        $this->assertStringContainsString('public function resolveForWrite(string $id, array $filters)', $emitted);
        $this->assertStringContainsString('return \App\Models\Verse::class;', $emitted);
        $this->assertStringNotContainsString('StreamsRecords', $emitted);
        $this->assertStringNotContainsString('ResolvesRecord,', $emitted);

        // The emitted file is real PHP that honors its declared capabilities.
        require_once $this->host.'/app/Models/Verse.php';
        require_once $this->host.'/app/Particles/Backings/VerseBacking.php';
        $reflection = new \ReflectionClass(\App\Particles\Backings\VerseBacking::class);
        $this->assertTrue($reflection->implementsInterface(\Splicewire\Beam\Particle\Backing\ResourceBacking::class));
        $this->assertTrue($reflection->implementsInterface(\Splicewire\Beam\Particle\Backing\BacksModel::class));
        $this->assertTrue($reflection->implementsInterface(\Splicewire\Beam\Particle\Backing\WritesRecords::class));
    }

    public function test_the_stream_flag_swaps_the_read_capability(): void
    {
        $this->artisan('splicewire:beam:make:particle-backing', [
            'name' => 'FeedBacking',
            '--stream' => true,
        ])->assertSuccessful();

        $emitted = $this->read('app/Particles/Backings/FeedBacking.php');

        $this->assertStringContainsString('StreamsRecords', $emitted);
        $this->assertStringContainsString('CursorPaginator', $emitted);
        $this->assertStringNotContainsString('QueriesRecords', $emitted);
    }
}
