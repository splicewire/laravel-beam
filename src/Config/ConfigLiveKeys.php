<?php

namespace Splicewire\Beam\Config;

/**
 * The `live` keys every registered section declares, as config dot paths, and whether a config read touches one
 * (config-sections ticket 06). Shared by {@see \Splicewire\Beam\Doctor\BootReadConfigAudit} (the static scan) and
 * {@see \Splicewire\Beam\Testing\BootReadProbe} (the runtime authority), so both answer the same question.
 *
 * A read TOUCHES a live key when it reads the key itself, a key below it, or any ancestor (`config('beam.ux')` hands
 * back `releases.review_required` too). A map entry name is `*` and matches any one segment. The empty path is the whole
 * repository (`config()->all()`, `config()->get(null)`), so it touches every live key: a read that returns a subtree
 * counts as a read of every live key inside it.
 */
class ConfigLiveKeys
{
    /** @return list<string> dot paths, `*` for a map entry name */
    public static function in(ConfigSectionRegistry $registry): array
    {
        $live = [];

        foreach ($registry->all() as $registered) {
            foreach (ConfigSectionCoverage::liveKeys($registered->section) as $pointer) {
                $live[] = $registered->root().'.'.implode('.', JsonPointer::segments($pointer));
            }
        }

        return $live;
    }

    /**
     * The live keys a read of `$read` touches.
     *
     * @param  list<string>  $live
     * @return list<string>
     */
    public static function touchedBy(string $read, array $live): array
    {
        // The empty path is the whole repository (`all()`, `get(null)`): the ancestor of every key.
        if ($read === '') {
            return $live;
        }

        $readSegments = explode('.', $read);
        $touched = [];

        foreach ($live as $key) {
            $liveSegments = explode('.', $key);
            $shared = min(count($readSegments), count($liveSegments));
            $matches = true;

            for ($i = 0; $i < $shared; $i++) {
                if ($liveSegments[$i] !== '*' && $liveSegments[$i] !== $readSegments[$i]) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                $touched[] = $key;
            }
        }

        return $touched;
    }
}
