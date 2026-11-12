<?php

namespace Splicewire\Beam\Localization;

use Illuminate\Translation\MessageSelector;
use ReflectionMethod;
use RuntimeException;

/**
 * Which plural rules a line is selected by (localization ruling 02 §2, ticket 07).
 *
 * A plural is selected by the rules of the locale whose line ANSWERED (its `sourceLocale`), not the locale that was
 * asked for. Before selection the answering locale is normalized to an alias the framework's selector knows: the full
 * alias first (`pt-BR` → `pt_BR`), then its language subtag (`es-419` → `es`). A locale whose language the selector
 * does not know at all is passed through unchanged, and the selector's own default (index 0) applies.
 *
 * The known aliases are read from the INSTALLED selector's source (its `getPluralIndex()` switch), so they follow the
 * framework a host actually runs. The committed plural fixture (tests/Fixtures/Localization/plural-selector.json)
 * records them with the selector's source hash, and a test compares the installed selector against it, so a framework
 * upgrade that changes the rules fails loudly.
 */
final class PluralLocale
{
    /** @var list<string>|null */
    private static ?array $aliases = null;

    public static function forSelector(string $answeringLocale): string
    {
        $aliases = self::aliases();
        $canonical = LocaleChain::canonical($answeringLocale);
        $full = str_replace('-', '_', $canonical);

        if (in_array($full, $aliases, true)) {
            return $full;
        }

        $language = explode('-', $canonical)[0];

        return in_array($language, $aliases, true) ? $language : $answeringLocale;
    }

    /**
     * Every locale alias the installed selector's `getPluralIndex()` switch names, in source order.
     *
     * @return list<string>
     */
    public static function aliases(): array
    {
        if (self::$aliases !== null) {
            return self::$aliases;
        }

        $method = new ReflectionMethod(MessageSelector::class, 'getPluralIndex');
        $file = (string) $method->getFileName();
        $lines = array_slice(file($file) ?: [], $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $tokens = token_get_all("<?php\n".implode('', $lines));

        $aliases = [];
        $afterCase = false;
        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $afterCase = $afterCase && $token === ':' ? false : $afterCase;

                continue;
            }
            if ($token[0] === T_CASE) {
                $afterCase = true;
            } elseif ($afterCase && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $aliases[] = substr($token[1], 1, -1);
                $afterCase = false;
            }
        }

        return self::$aliases = array_values(array_unique($aliases));
    }

    /** The installed selector's source file, hashed: the fixture's baseline. */
    public static function selectorSourceSha256(): string
    {
        $file = (new ReflectionMethod(MessageSelector::class, 'getPluralIndex'))->getFileName();
        $hash = $file === false ? false : hash_file('sha256', $file);

        if ($hash === false) {
            throw new RuntimeException('The installed MessageSelector source cannot be read, so the plural fixture has no baseline.');
        }

        return $hash;
    }
}
