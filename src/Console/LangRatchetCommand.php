<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Localization\Ratchet\LiteralTextCensus;
use Splicewire\Beam\Localization\Ratchet\TextRatchet;

/**
 * The PHP ratchet (localization 12): user-facing literals in a package's response-reaching calls, per file, against its
 * committed `lang-ratchet.json`. Without `--write` it compares and writes nothing.
 *   php artisan beam:lang:ratchet vendor/splicewire/laravel-beam-ux
 *   php artisan beam:lang:ratchet --write .
 */
class LangRatchetCommand extends Command
{
    protected $signature = 'beam:lang:ratchet
        {root=. : the package root (its src/ is read; its lang-ratchet.json is compared or written)}
        {--write : write the baseline instead of comparing}';

    protected $description = 'Count user-facing literals in a package\'s response-reaching calls against its ratchet baseline.';

    public function handle(): int
    {
        $argument = $this->argument('root');
        $given = is_string($argument) ? $argument : '.';
        $root = realpath($given) ?: $given;
        $this->line('Population: '.LiteralTextCensus::POPULATION);

        if ($this->option('write')) {
            $path = TextRatchet::write($root);
            $this->info(sprintf('Wrote %s: %d literal%s.', $path, TextRatchet::measure($root)['total'], TextRatchet::measure($root)['total'] === 1 ? '' : 's'));

            return self::SUCCESS;
        }

        $result = TextRatchet::compare($root);
        if ($result['missing']) {
            $this->error('No '.TextRatchet::FILE.' at '.$root.'. Write it with --write.');

            return self::FAILURE;
        }

        foreach ($result['rose'] as $file => [$was, $is]) {
            $this->error("ROSE {$file}: {$was} → {$is}");
        }
        foreach ($result['fell'] as $file => [$was, $is]) {
            $this->warn("FELL {$file}: {$was} → {$is} (re-write the baseline to keep the gain)");
        }
        // Excluded text is REPORTED with its reviewed reason, never silent.
        foreach (TextRatchet::excluded($root) as $hit) {
            $this->line(sprintf('excluded %s:%d%s (%s): %s', $hit['file'], $hit['line'], $hit['method'] === null ? '' : ' '.$hit['method'].'()', $hit['kind'], $hit['reason']));
        }
        foreach ($result['invalid'] as $error) {
            $this->error('INVALID '.$error);
        }
        foreach ($result['stale'] as $entry) {
            $this->error(sprintf('STALE exclusion %s%s matches no text: remove it.', $entry['file'], isset($entry['method']) ? '::'.$entry['method'] : ':'.$entry['line']));
        }
        $clean = $result['rose'] === [] && $result['fell'] === [] && $result['stale'] === [] && $result['invalid'] === [];
        if ($clean) {
            $this->info(sprintf('Unchanged: %d literal%s.', TextRatchet::measure($root)['total'], TextRatchet::measure($root)['total'] === 1 ? '' : 's'));
        }

        return $clean ? self::SUCCESS : self::FAILURE;
    }
}
