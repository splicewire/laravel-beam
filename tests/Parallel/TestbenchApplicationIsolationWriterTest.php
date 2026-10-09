<?php

namespace Splicewire\Beam\Tests\Parallel;

use Splicewire\Beam\Tests\TestCase;

class TestbenchApplicationIsolationWriterTest extends TestCase
{
    public function test_a_parallel_worker_cannot_delete_another_workers_testbench_file(): void
    {
        $runId = getenv('TESTBENCH_ISOLATION_RUN_ID');

        if (! is_string($runId) || getenv('TEST_TOKEN') === false) {
            $this->markTestSkipped('Run this regression with ParaTest and TESTBENCH_ISOLATION_RUN_ID.');
        }

        $barrier = sys_get_temp_dir().'/laravel-beam-testbench-isolation-'.$runId;
        $sentinel = base_path('config/beam/parallel-worker-sentinel.php');

        if (! is_dir(dirname($sentinel))) {
            mkdir(dirname($sentinel), 0777, true);
        }

        if (! is_dir($barrier)) {
            @mkdir($barrier, 0777, true);
        }
        file_put_contents($sentinel, '<?php return true;');
        touch($barrier.'/writer-ready');

        $this->waitFor($barrier.'/deleter-finished');

        $this->assertFileExists($sentinel);

        @unlink($barrier.'/writer-ready');
        @unlink($barrier.'/deleter-finished');
        @rmdir($barrier);
    }

    private function waitFor(string $path): void
    {
        $deadline = microtime(true) + 20;

        while (! file_exists($path) && microtime(true) < $deadline) {
            usleep(10_000);
        }

        $this->assertFileExists($path, "Timed out waiting for parallel worker barrier [{$path}].");
    }
}
