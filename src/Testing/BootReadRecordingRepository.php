<?php

namespace Splicewire\Beam\Testing;

use Illuminate\Config\Repository;

/**
 * The config repository {@see BootReadProbe} installs: a plain repository that tells the probe which key each read asked
 * for and who asked. Writes are untouched.
 */
class BootReadRecordingRepository extends Repository
{
    /** Callers whose reads are the configuration mechanism itself, never a provider holding a value. */
    private const MECHANISM = ['Illuminate\\', 'Splicewire\\Beam\\Config\\Overrides\\', 'Splicewire\\Beam\\Testing\\', 'Orchestra\\'];

    /** @param array<string, mixed> $items */
    public function __construct(array $items, private BootReadProbe $probe)
    {
        parent::__construct($items);
    }

    /**
     * Counted like a read: a boot decision that depends on whether a live key exists is still a boot-time dependency on
     * it (lead ruling, 2026-11-16).
     */
    public function has($key)
    {
        $this->note((string) $key);

        return parent::has($key);
    }

    /**
     * The whole repository is a read of every key in it, so it is recorded as the empty path, which
     * {@see \Splicewire\Beam\Config\ConfigLiveKeys::touchedBy()} counts as touching every live key (review-r1,
     * 2026-11-16: `config()->all()[$root][$key]` evaded the probe). `get(null)` records the same empty path.
     *
     * @return array<string, mixed>
     */
    public function all()
    {
        $this->note('');

        return parent::all();
    }

    /** @param array<array-key, mixed>|string $key */
    public function get($key, $default = null)
    {
        if (is_array($key)) {
            foreach (array_keys($key) as $name) {
                $this->note(is_string($name) ? $name : (string) $key[$name]);
            }
        } else {
            $this->note((string) $key);
        }

        return parent::get($key, $default);
    }

    public function getMany($keys)
    {
        foreach ($keys as $key => $default) {
            $this->note(is_numeric($key) ? (string) $default : (string) $key);
        }

        return parent::getMany($keys);
    }

    private function note(string $key): void
    {
        $origin = $this->origin();

        if ($origin !== null) {
            $this->probe->record($key, $origin);
        }
    }

    /** The first caller outside this repository and the `config()` / facade plumbing, or null when it is the mechanism. */
    private function origin(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $class = $frame['class'] ?? null;

            if ($class === self::class || $class === Repository::class || $class === null && $frame['function'] === 'config') {
                continue;
            }

            if ($class === null) {
                return $frame['function'].'()';
            }

            foreach (self::MECHANISM as $prefix) {
                if (str_starts_with($class, $prefix) && ! str_starts_with($class, 'Illuminate\\Support\\Facades\\')) {
                    return null;
                }
            }

            if (str_starts_with($class, 'Illuminate\\Support\\Facades\\')) {
                continue;
            }

            return $class.'::'.$frame['function'];
        }

        return null;
    }
}
