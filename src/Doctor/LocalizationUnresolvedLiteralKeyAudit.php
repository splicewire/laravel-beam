<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Localization\Catalog\SourceScanner;
use Splicewire\Beam\Localization\TextNamespaces;

/**
 * `localization.unresolved-literal-key` (localization 12): a literal namespaced key (`__('beam-ux::nav.title')`) that
 * resolves nowhere in English, in a family package's shipped source or the host's own. In a test it already throws (the
 * gate); this finds the call no test exercises. Advisory: a third-party namespace is not the family's to report.
 */
final class LocalizationUnresolvedLiteralKeyAudit implements DoctorAudit
{
    public const CHECK = 'localization.unresolved-literal-key';

    public const POPULATION = 'PHP-emitted keys only (client keys join with localization 16): literal namespaced keys in '
        .'__()/trans()/trans_choice()/Lang::get()/@lang calls under each family package\'s src, resources/views, routes '
        .'and config, and the host\'s app, resources/views and routes.';

    /** @param  list<string>|null  $hostDirs  the host's source directories; null: `app`, `resources/views`, `routes` */
    public function __construct(
        private TextNamespaces $namespaces,
        private ?array $hostDirs = null,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $translator = app('translator');
        if (! $translator instanceof BeamTranslator) {
            return [Finding::inconclusive(self::CHECK, 'The application translator is not beam\'s ladder. '.self::POPULATION)];
        }

        $dirs = [];
        foreach ($this->namespaces->familyPackages() as $name => $package) {
            $this->namespaces->ownerOfPackage($name); // hint, so its lines can answer
            foreach (['src', 'resources/views', 'routes', 'config'] as $dir) {
                $dirs[] = $package['root'].'/'.$dir;
            }
        }
        foreach ($this->hostDirs ?? [base_path('app'), base_path('resources/views'), base_path('routes')] as $dir) {
            $dirs[] = $dir;
        }

        $seen = 0;
        $findings = [];
        foreach (SourceScanner::files(array_values(array_unique($dirs))) as $file) {
            foreach (SourceScanner::literalKeys($file) as ['key' => $key, 'line' => $line]) {
                $namespace = strstr($key, '::', true);
                if ($namespace === false || $this->namespaces->familyOwnersOf($namespace) === []) {
                    continue;
                }
                $seen++;
                if ($translator->resolve($key, 'en', false) === null) {
                    $findings[] = Finding::warn(self::CHECK, sprintf(
                        '%s:%d asks for [%s], which has no English line in its namespace\'s lang/en. It renders the raw key. Add the line or fix the key.',
                        self::relative($file), $line, $key,
                    ));
                }
            }
        }

        if ($findings !== []) {
            return $findings;
        }

        return $seen === 0
            ? [Finding::inconclusive(self::CHECK, 'No literal namespaced key in a family namespace was found. '.self::POPULATION)]
            : [Finding::pass(self::CHECK, sprintf('All %d literal namespaced key%s resolve in English. %s', $seen, $seen === 1 ? '' : 's', self::POPULATION))];
    }

    private static function relative(string $file): string
    {
        return str_starts_with($file, base_path().'/') ? substr($file, strlen(base_path()) + 1) : $file;
    }
}
