<?php

namespace Splicewire\Beam\Tests\Parallel;

use Splicewire\Beam\Tests\TestCase;

class TestbenchApplicationIsolationDeleterTest extends TestCase
{
    public function test_a_parallel_worker_mutates_only_its_own_testbench_application(): void
    {
        $runId = getenv('TESTBENCH_ISOLATION_RUN_ID');

        if (! is_string($runId) || getenv('TEST_TOKEN') === false) {
            $this->markTestSkipped('Run this regression with ParaTest and TESTBENCH_ISOLATION_RUN_ID.');
        }

        $barrier = sys_get_temp_dir().'/laravel-beam-testbench-isolation-'.$runId;
        $sentinel = base_path('config/beam/parallel-worker-sentinel.php');

        if (! is_dir($barrier)) {
            @mkdir($barrier, 0777, true);
        }
        $this->waitFor($barrier.'/writer-ready');

        @unlink($sentinel);
        touch($barrier.'/deleter-finished');

        $this->assertFileDoesNotExist($sentinel);
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
