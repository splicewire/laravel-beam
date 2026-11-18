<?php

namespace Splicewire\Beam\Tests\Registries;

use PHPUnit\Framework\Attributes\Test;
use Splicewire\Beam\Tests\TestCase;

/**
 * **The DataExamples capture app runs with app.debug=false whatever the host's env says.**
 *
 * tests/regenerate-data-examples.php and DataExamplesTest both capture through an app built from
 * {@see TestCase}. A host whose environment sets APP_DEBUG=true (a stray testbench skeleton `.env` in a
 * copied vendor tree did, 2026-11-18) rendered a 404's exception, file and trace into the captured body,
 * so graph-operations.json drifted on that host only. TestCase pins the value; this proves the pin wins
 * over the env.
 */
class DataExamplesDebugPinTest extends TestCase
{
    #[Test]
    public function test_the_capture_app_is_not_in_debug_even_when_the_env_asks_for_it(): void
    {
        $before = [getenv('APP_DEBUG'), $_ENV['APP_DEBUG'] ?? null, $_SERVER['APP_DEBUG'] ?? null];
        putenv('APP_DEBUG=true');
        $_ENV['APP_DEBUG'] = $_SERVER['APP_DEBUG'] = 'true';

        try {
            // Built exactly as tests/regenerate-data-examples.php builds its capture app.
            $app = (new class('data-examples') extends TestCase {})->createApplication();

            $this->assertTrue((bool) env('APP_DEBUG'), 'control: the env really asks for debug');
            $this->assertFalse($app['config']->get('app.debug'));
        } finally {
            $before[0] === false ? putenv('APP_DEBUG') : putenv('APP_DEBUG='.$before[0]);
            unset($_ENV['APP_DEBUG'], $_SERVER['APP_DEBUG']);
            if ($before[1] !== null) {
                $_ENV['APP_DEBUG'] = $before[1];
            }
            if ($before[2] !== null) {
                $_SERVER['APP_DEBUG'] = $before[2];
            }
        }

        $this->assertFalse($this->app['config']->get('app.debug'));
    }
}
