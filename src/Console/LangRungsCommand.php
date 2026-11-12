<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Localization\BeamTranslator;

/**
 * Where a string comes from (localization ticket 07, story 23): for a key, a locale and a realm, every rung the ladder
 * tried, in order, and the one that answered. Read-only.
 *
 *   php artisan beam:lang:rungs 'beam-ux::resources.beam-ux-redirect.label' --locale=es-MX --realm=tenant
 */
class LangRungsCommand extends Command
{
    protected $signature = 'beam:lang:rungs
        {key : the translation key, e.g. beam-ux::resources.beam-ux-redirect.label}
        {--locale= : the requested locale (default: the application locale)}
        {--realm= : the realm (default: none, the base)}';

    protected $description = 'Show every rung the localization ladder tries for a key, a locale and a realm, and which one answered.';

    public function handle(): int
    {
        $translator = $this->laravel->make('translator');
        if (! $translator instanceof BeamTranslator) {
            $this->error('The application translator is not beam\'s ladder.');

            return self::FAILURE;
        }

        $key = $this->argument('key');
        $localeOption = $this->option('locale');
        $realmOption = $this->option('realm');

        if (! is_string($key) || $key === '') {
            $this->error('Give a translation key.');

            return self::INVALID;
        }

        $locale = is_string($localeOption) && $localeOption !== '' ? $localeOption : $translator->getLocale();
        $realm = is_string($realmOption) && $realmOption !== '' ? $realmOption : null;

        $trace = $translator->trace($key, $locale, $realm);

        $this->line(sprintf('%s  locale=%s  realm=%s', $key, $locale, $realm ?? '(base)'));
        $rows = [];
        foreach ($trace['rungs'] as $i => $rung) {
            $rows[] = [$i + 1, $rung['locale'], $rung['realm'] ?? '(base)', $rung['origin'], $rung['hit'] ? 'ANSWERED' : 'miss'];
        }
        $this->table(['#', 'locale', 'realm rung', 'source', 'result'], $rows);

        $answer = $trace['answer'];
        if ($answer === null) {
            $this->warn('No rung answered: the key renders as its default.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Answered by %s %s (sourceLocale %s): %s',
            $answer->realm === null ? 'the base' : "realm rung [{$answer->realm}]",
            $answer->origin === 'config' ? 'from the realm\'s configured label' : 'from a lang file',
            $answer->sourceLocale,
            is_string($answer->line) ? $answer->line : json_encode($answer->line),
        ));

        return self::SUCCESS;
    }
}
