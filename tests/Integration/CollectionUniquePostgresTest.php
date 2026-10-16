<?php

namespace Splicewire\Beam\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Collections\CollectionDefinition;
use Splicewire\Beam\Collections\CollectionUniqueWrite;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Tests\TestCase;

/** Opt-in real two-connection proof; EP34_PG_DATABASE must name an explicitly created scratch DB. */
class CollectionUniquePostgresTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $database = getenv('EP34_PG_DATABASE');
        if (! $database) {
            return;
        }
        if (! preg_match('/^ep34_unique_[a-z0-9_]+$/', $database)) {
            throw new \RuntimeException('Refusing a non-scratch PostgreSQL database.');
        }
        $app['config']->set('database.default', 'ep34');
        $app['config']->set('database.connections.ep34', [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432,
            'database' => $database, 'username' => 'root', 'password' => '',
            'charset' => 'utf8', 'prefix' => '', 'schema' => 'public',
        ]);
    }

    public function test_two_concurrent_equal_values_produce_one_success_and_one_refusal(): void
    {
        if (! getenv('EP34_PG_DATABASE')) {
            $this->markTestSkipped('Requires an owned EP34_PG_DATABASE scratch database and pcntl.');
        }
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        Schema::create(Beam::table('schemas'), function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('schema_name');
            $table->integer('version');
        });
        Schema::create(Beam::table('particles'), function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('schema_ref');
            $table->string('source_tier');
            $table->json('payload');
        });
        $stem = 'https://example.test/schemas/concurrent';
        DB::table(Beam::table('schemas'))->insert(['id' => 'anchor', 'schema_name' => $stem, 'version' => 1]);
        $definition = new CollectionDefinition([
            '$id' => $stem.'/1', 'type' => 'object',
            'x-beam-collection' => ['label' => 'Concurrent', 'nameField' => 'slug'],
            'properties' => ['slug' => ['type' => 'string', 'x-beam-unique' => true]],
        ]);
        DB::purge('ep34');
        $children = [];
        $pipes = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                stream_set_timeout($parent, 15);
                stream_set_timeout($child, 15);
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('fork failed');
                }
                if ($pid === 0) {
                    fclose($parent);
                    try {
                        DB::purge('ep34');
                        DB::statement("SET lock_timeout = '10s'");
                        fwrite($child, DB::selectOne('select pg_backend_pid() as pid')->pid."\n");
                        app(CollectionUniqueWrite::class)->run(new BeamParticle, $definition, [$stem, $stem.'/1'], ['slug' => 'same'], function () use ($i, $child, $stem): void {
                            if ($i === 0) {
                                fwrite($child, "locked\n");
                                if (trim((string) fgets($child)) !== 'release') {
                                    throw new \RuntimeException('release barrier failed');
                                }
                            }
                            DB::table(Beam::table('particles'))->insert(['id' => 'record-'.$i, 'schema_ref' => $stem.'/1', 'source_tier' => 'local', 'payload' => json_encode(['slug' => 'same'])]);
                        });
                        fwrite($child, "success\n");
                    } catch (ValidationException) {
                        fwrite($child, "refusal\n");
                    } catch (\Throwable $e) {
                        fwrite($child, 'error:'.$e->getMessage()."\n");
                    }
                    DB::disconnect('ep34');
                    fclose($child);
                    exit(0);
                }
                fclose($child);
                $children[] = $pid;
                $pipes[] = $parent;
                $backend = (int) trim((string) fgets($parent));
                $this->assertGreaterThan(0, $backend);
                if ($i === 0) {
                    $this->assertSame('locked', trim((string) fgets($parent)));
                }
            }
            // Observe a real PostgreSQL lock wait, not timing-based "concurrency".
            $deadline = microtime(true) + 8;
            do {
                $blocked = (int) DB::selectOne('select cardinality(pg_blocking_pids(?)) as n', [$backend])->n;
                if ($blocked > 0) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            fwrite($pipes[0], "release\n");
            $results = array_map(fn ($pipe) => trim((string) fgets($pipe)), $pipes);
            $this->assertGreaterThan(0, $blocked, 'Second writer must wait for the schema anchor row.');
            $this->assertSame(['success', 'refusal'], $results);
            $this->assertSame(1, DB::table(Beam::table('particles'))->count());
        } finally {
            foreach ($pipes as $pipe) {
                @fwrite($pipe, "release\n");
                fclose($pipe);
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }
        }
    }
}
