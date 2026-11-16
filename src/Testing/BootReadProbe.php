<?php

namespace Splicewire\Beam\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Splicewire\Beam\Config\ConfigLiveKeys;
use Splicewire\Beam\Config\ConfigSectionRegistry;

/**
 * TEST-ONLY: records every config key the application reads while it boots, so a test can prove that no key declared
 * `live` is read before boot ends (config-sections ticket 06). A value a provider reads during `register`/`boot` is held
 * in that provider for the life of the process; the operator overlay's next sync cannot reach it, so a key read there
 * is not live whatever its declaration says.
 *
 * It is the AUTHORITY over {@see \Splicewire\Beam\Doctor\BootReadConfigAudit}: the static scan sees literal reads in
 * provider bodies, and the probe sees every read that actually happens, including dynamic keys and helpers a provider
 * calls. Install it right after the configuration loads and before any provider registers:
 *
 *     protected function resolveApplicationConfiguration($app)
 *     {
 *         parent::resolveApplicationConfiguration($app);
 *         $this->probe = BootReadProbe::install($app);
 *     }
 *
 *     public function test_no_live_key_is_read_during_boot(): void
 *     {
 *         $this->probe->stop();
 *         $this->assertSame([], $this->probe->violations(app(ConfigSectionRegistry::class)));
 *     }
 *
 * Every read shape counts, conservatively: a key, a key below a live one, an ancestor (`config('beam.ux')`), the whole
 * repository (`config()->all()`, `config()->get(null)`), array access, and `has()`. A read that returns a subtree is a
 * read of every live key inside it. Reads made by the framework (`mergeConfigFrom()` merging a package's defaults) and by
 * the override overlay itself are the mechanism, not a provider holding a value, and are not recorded.
 */
class BootReadProbe
{
    /** @var array<string, list<string>> key => where it was read (`Class::method`) */
    private array $reads = [];

    private bool $recording = true;

    public static function install(Application $app): self
    {
        $probe = new self;
        $app->instance('config', new BootReadRecordingRepository($app->make('config')->all(), $probe));
        Facade::clearResolvedInstance('config');

        return $probe;
    }

    /** Called by the recording repository for each key read. */
    public function record(string $key, string $origin): void
    {
        if ($this->recording) {
            $this->reads[$key][] = $origin;
        }
    }

    /** End the boot window: reads after this are the application running, not booting. */
    public function stop(): void
    {
        $this->recording = false;
    }

    /** @return array<string, list<string>> every key read before {@see stop()}, with where it was read */
    public function reads(): array
    {
        return $this->reads;
    }

    /**
     * One line per live key a boot-time read touched, naming the read and where it happened.
     *
     * @return list<string>
     */
    public function violations(ConfigSectionRegistry $registry): array
    {
        $live = ConfigLiveKeys::in($registry);
        $violations = [];

        foreach ($this->reads as $key => $origins) {
            foreach (ConfigLiveKeys::touchedBy($key, $live) as $touched) {
                $violations[] = sprintf('%s is declared live but was read during boot (as %s, in %s)', $touched, $key === '' ? 'the whole configuration' : "`{$key}`", implode(', ', array_unique($origins)));
            }
        }

        return $violations;
    }
}
