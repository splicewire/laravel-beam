<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Splicewire\Beam\Console\BeamInstallCommand;
use Splicewire\Beam\Install\MigrationCollision;
use Splicewire\Beam\Install\TableOwnershipResolver;
use Splicewire\Beam\Schema\ConvergentTable;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Table ownership (beam-facade ticket 29): the installer's one answer that ACTS.
 *
 * The lever under test is filename order, and it is only ever spent against a package whose migration
 * guard beam does not own — inside the family {@see ConvergentTable} already
 * dissolves the collision. Fixtures are real files on disk because the whole mechanism is a `glob` and
 * a `rename`; mocking the filesystem would test the mock.
 */
class TableOwnershipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-table-ownership-'.getmypid();
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->ours());
        File::ensureDirectoryExists($this->theirs());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    /** A published copy under `database/migrations/**` — ours to re-date. */
    private function ours(): string
    {
        return $this->root.'/database/migrations/shared';
    }

    /** A path a third-party package registered from its own source — never ours to touch. */
    private function theirs(): string
    {
        return $this->root.'/vendor/acme/core/database/migrations';
    }

    private function resolver(): TableOwnershipResolver
    {
        return new TableOwnershipResolver(
            [$this->ours(), $this->theirs()],
            $this->root.'/database',
        );
    }

    private function write(string $dir, string $name, string $body = ''): string
    {
        File::put($path = $dir.'/'.$name, "<?php\n".$body);

        return $path;
    }

    /**
     * The live shape at `~/Herd/splicewire-app`: beam publishes `create_media_table` at `now()` and
     * `lunarphp/core` ships one at a fixed `2026_01_01_*`, so beam loses and the loser's guard reports
     * success over the winner's schema.
     */
    public function test_it_finds_a_published_migration_losing_to_a_third_party_one(): void
    {
        $this->write($this->ours(), '2026_08_14_023918_create_media_table.php');
        $this->write($this->theirs(), '2026_01_01_000011_create_media_table.php');

        $collisions = $this->resolver()->collisions();

        $this->assertCount(1, $collisions);
        $this->assertSame('create_media_table', $collisions[0]->stem);
        $this->assertSame('acme/core', $collisions[0]->competitor());
        $this->assertFalse($collisions[0]->beamWins());
    }

    /**
     * MINIMAL DISPLACEMENT, not a `0001_01_01_*` band: one tick below the competitor is enough to win,
     * and re-stamping a file moves it relative to every other published file — which
     * `migration-publish-ordering.convention.md` records as a real incident, not a hypothetical.
     */
    public function test_claiming_re_dates_ours_to_one_tick_below_the_competitor(): void
    {
        $this->write($this->ours(), '2026_08_14_023918_create_media_table.php');
        $this->write($this->theirs(), '2026_01_01_000011_create_media_table.php');

        $resolver = $this->resolver();
        $new = $resolver->claim($resolver->collisions()[0]);

        $this->assertSame($this->ours().'/2026_01_01_000010_create_media_table.php', $new);
        $this->assertFileExists($new);
        $this->assertFileDoesNotExist($this->ours().'/2026_08_14_023918_create_media_table.php');
    }

    /** Beating the EARLIEST competitor beats the rest — one comparison, however many copies there are. */
    public function test_it_beats_the_earliest_of_several_competitors(): void
    {
        $this->write($this->ours(), '2026_08_14_023918_create_media_table.php');
        $this->write($this->theirs(), '2026_01_01_000011_create_media_table.php');
        $this->write($this->theirs(), '2026_05_05_000000_create_media_table.php');

        $resolver = $this->resolver();
        $collision = $resolver->collisions()[0];

        $this->assertCount(2, $collision->theirFiles);
        $this->assertSame('2026_01_01_000011', $collision->theirPrefix);
        $this->assertSame($this->ours().'/2026_01_01_000010_create_media_table.php', $resolver->claim($collision));
    }

    /**
     * Idempotent by construction: a re-run of the installer sees the collision, sees that beam already
     * wins, and does nothing. This is what makes the pass safe to leave in the install path forever.
     */
    public function test_a_collision_beam_already_wins_is_reported_but_never_moved(): void
    {
        $this->write($this->ours(), '0001_01_01_000002_create_activity_log_table.php');
        $this->write($this->theirs(), '2026_01_01_900001_create_activity_log_table.php');

        $resolver = $this->resolver();
        $collision = $resolver->collisions()[0];

        $this->assertTrue($collision->beamWins());
        $this->assertNull($resolver->winningPrefix($collision));
        $this->assertNull($resolver->claim($collision));
        $this->assertFileExists($this->ours().'/0001_01_01_000002_create_activity_log_table.php');
    }

    /**
     * Two migrations a HOST wrote itself are both "ours" and are deliberately invisible here — beam has
     * no standing to arbitrate between two of an app's own migrations
     * (`convergent-migration-guards.convention.md`, "Scope"). The asymmetry that licenses the rename is
     * that we may re-date a published copy and may never touch a package's own source.
     */
    public function test_two_host_owned_migrations_are_not_a_collision_this_command_arbitrates(): void
    {
        $this->write($this->ours(), '2026_08_14_023918_create_media_table.php');
        $this->write($this->root.'/database/migrations', '2026_09_01_000000_create_media_table.php');

        $resolver = new TableOwnershipResolver(
            [$this->ours(), $this->root.'/database/migrations', $this->theirs()],
            $this->root.'/database',
        );

        $this->assertSame([], $resolver->collisions());
    }

    /**
     * The trap `migration-publish-ordering.convention.md` records under "re-stamping one file to move
     * it". Moving a CREATE earlier is safe for every ALTER against that table — they stay after it — and
     * unsafe only where the create itself references a table created later. Warned, never blocked: the
     * operator declared the ownership and a failed migrate is the loud failure this family exists to
     * convert silence into.
     */
    public function test_it_warns_when_the_claimed_create_references_a_table_created_later(): void
    {
        $this->write($this->ours(), '2026_08_14_023918_create_media_table.php',
            "Schema::create('media', fn (\$t) => \$t->foreignId('team_id')->constrained('teams'));");
        $this->write($this->ours(), '2026_09_01_000000_create_teams_table.php', "Schema::create('teams', fn () => null);");
        $this->write($this->theirs(), '2026_01_01_000011_create_media_table.php');

        $resolver = $this->resolver();
        $collision = $resolver->collisions()[0];

        $risks = $resolver->dependencyRisks($collision, $resolver->winningPrefix($collision));

        $this->assertCount(1, $risks);
        $this->assertStringContainsString('references `teams`', $risks[0]);
        $this->assertStringContainsString('2026_09_01_000000_create_teams_table', $risks[0]);
    }

    /**
     * A dynamically-named FK target is skipped rather than guessed at — `MigrationOrderingAudit`'s
     * posture, and the reason the collision unit is the filename stem in the first place. Under-reporting
     * beats a confident warning about a table that does not exist under that name.
     */
    public function test_a_dynamically_named_dependency_is_skipped_not_guessed_at(): void
    {
        $this->write($this->ours(), '2026_08_14_023918_create_media_table.php',
            "Schema::create('media', fn (\$t) => \$t->foreignId('p')->constrained(Beam::table('particles')));");
        $this->write($this->ours(), '2026_09_01_000000_create_beam_particles_table.php',
            "Schema::create(Beam::table('particles'), fn () => null);");
        $this->write($this->theirs(), '2026_01_01_000011_create_media_table.php');

        $resolver = $this->resolver();
        $collision = $resolver->collisions()[0];

        $this->assertSame([], $resolver->dependencyRisks($collision, $resolver->winningPrefix($collision)));
    }

    /**
     * `--own-tables` is the scriptable form of the prompt: authoritative even when EMPTY, which is how a
     * host declines every claim and takes the competitor's schema (plus, thanks to the convergent guard,
     * a loud red migrate rather than a quiet wrong table). Absent ⇒ all, the defaults-true answer
     * `--no-interaction` takes silently.
     */
    public function test_own_tables_option_is_authoritative_even_when_empty(): void
    {
        $contested = [
            $this->collision('create_media_table'),
            $this->collision('create_tags_table'),
        ];

        $this->assertSame(['create_media_table', 'create_tags_table'], $this->select($contested, null));
        $this->assertSame(['create_media_table'], $this->select($contested, 'media'));
        $this->assertSame([], $this->select($contested, ''));
    }

    /**
     * The whole pass through the real command: publish, then ASK, then migrate. The answer sits between
     * publish and migrate because the question's options are the files publish just wrote and the answer
     * has to land before anything runs them — so an artisan-level test is the only one that can prove the
     * sequencing, which is the part of this feature a unit test cannot reach.
     */
    public function test_the_install_command_claims_a_contested_table_before_migrating(): void
    {
        $competitor = $this->root.'/competitor';
        File::ensureDirectoryExists($competitor);
        $this->migration($competitor.'/2026_01_01_000011_create_beam_widgets_table.php');

        $published = base_path('database/migrations/2026_08_14_023918_create_beam_widgets_table.php');
        $this->migration($published);

        $this->app->make('migrator')->path($competitor);

        try {
            $this->artisan('splicewire:beam:install', ['--own-tables' => 'widgets', '--no-interaction' => true])
                ->expectsOutputToContain('table ownership')
                ->expectsOutputToContain('create_beam_widgets_table')
                ->assertExitCode(0);

            $this->assertFileDoesNotExist($published);
            $this->assertFileExists(base_path('database/migrations/2026_01_01_000010_create_beam_widgets_table.php'));
        } finally {
            foreach (glob(base_path('database/migrations/*_create_beam_widgets_table.php')) ?: [] as $leaked) {
                @unlink($leaked);
            }
        }
    }

    /** A migration that parses and does nothing — the `migrate` this command runs will pick it up. */
    private function migration(string $path): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;

        return new class extends Migration
        {
            public function up(): void {}

            public function down(): void {}
        };
        PHP);
    }

    private function collision(string $stem): MigrationCollision
    {
        return new MigrationCollision(
            stem: $stem,
            ourFile: "/app/database/migrations/2026_08_14_023918_{$stem}.php",
            ourPrefix: '2026_08_14_023918',
            theirFiles: ["/app/vendor/acme/core/database/migrations/2026_01_01_000011_{$stem}.php"],
            theirPrefix: '2026_01_01_000011',
        );
    }

    /**
     * @param  list<MigrationCollision>  $contested
     * @return list<string>
     */
    private function select(array $contested, ?string $option): array
    {
        $command = $this->app->make(BeamInstallCommand::class);
        $command->setLaravel($this->app);

        $input = new ArrayInput(
            $option === null ? [] : ['--own-tables' => $option],
            $command->getDefinition(),
        );
        $input->setInteractive(false);

        (new \ReflectionProperty(Command::class, 'input'))->setValue($command, $input);

        $method = new \ReflectionMethod($command, 'selectOwnedTables');
        $method->setAccessible(true);

        return array_map(
            static fn (MigrationCollision $c): string => $c->stem,
            $method->invoke($command, $contested, false),
        );
    }
}
