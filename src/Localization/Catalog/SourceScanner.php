<?php

namespace Splicewire\Beam\Localization\Catalog;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Static reads of PHP and Blade source for localization 12's audits and catalog, on PHP's own tokenizer (no parser
 * dependency). Read-only. A Blade template is a different grammar, so it is read with a pattern and says so.
 */
final class SourceScanner
{
    /** The translation calls whose first argument is a key. */
    private const KEY_CALLS = ['__', 'trans', 'trans_choice'];

    /**
     * Every PHP file under `$dirs` that exist, sorted.
     *
     * @param  list<string>  $dirs
     * @return list<string>
     */
    public static function files(array $dirs, string $suffix = '.php'): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                if (str_ends_with($path, $suffix)) {
                    $files[] = $path;
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The literal NAMESPACED keys (`ns::group.item`) a file passes to `__()`, `trans()`, `trans_choice()`,
     * `Lang::get()`/`choice()` or Blade's `@lang`. A literal that is only a concatenated prefix is not a key.
     *
     * @return list<array{key: string, line: int}>
     */
    public static function literalKeys(string $path): array
    {
        $source = (string) @file_get_contents($path);

        if (str_ends_with($path, '.blade.php')) {
            $found = [];
            if (preg_match_all('/(?:@lang|__|trans_choice|trans)\(\s*([\'"])([a-z0-9][a-z0-9_.-]*::[^\'"]+)\1\s*[,)]/', $source, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[2] as [$key, $offset]) {
                    $found[] = ['key' => $key, 'line' => substr_count(substr($source, 0, $offset), "\n") + 1];
                }
            }

            return $found;
        }

        $tokens = PhpToken::tokenize($source);
        $found = [];
        foreach ($tokens as $i => $token) {
            if (! $token->is(T_STRING)) {
                continue;
            }
            $isCall = in_array($token->text, self::KEY_CALLS, true) && ! self::isMember($tokens, $i);
            $isLang = in_array($token->text, ['get', 'choice'], true) && self::isLangStatic($tokens, $i);
            if (! $isCall && ! $isLang) {
                continue;
            }
            $argument = self::firstArgument($tokens, $i);
            if ($argument === null) {
                continue;
            }
            [$literal, $next] = $argument;
            $key = self::unquote($literal->text);
            if ($next !== null && $next->text === '.') {
                continue;
            }
            if (preg_match('/^[a-z0-9][a-z0-9_.-]*::/', $key) === 1) {
                $found[] = ['key' => $key, 'line' => $literal->line];
            }
        }

        return $found;
    }

    /**
     * The fully qualified names of the enums a PHP file declares.
     *
     * @return list<string>
     */
    public static function enums(string $path): array
    {
        $tokens = PhpToken::tokenize((string) @file_get_contents($path));
        $namespace = '';
        $enums = [];
        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                $name = '';
                for ($j = $i + 1; isset($tokens[$j]) && ! in_array($tokens[$j]->text, [';', '{'], true); $j++) {
                    if ($tokens[$j]->is([T_NAME_QUALIFIED, T_STRING])) {
                        $name .= $tokens[$j]->text;
                    }
                }
                $namespace = $name;
            }
            if ($token->is(T_ENUM)) {
                for ($j = $i + 1; isset($tokens[$j]); $j++) {
                    if ($tokens[$j]->is(T_STRING)) {
                        $enums[] = ltrim($namespace.'\\'.$tokens[$j]->text, '\\');
                        break;
                    }
                    if (! $tokens[$j]->is(T_WHITESPACE)) {
                        break;
                    }
                }
            }
        }

        return $enums;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private static function isMember(array $tokens, int $i): bool
    {
        $previous = self::previous($tokens, $i);

        return $previous !== null && ($previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]));
    }

    /** @param  array<int, PhpToken>  $tokens */
    private static function isLangStatic(array $tokens, int $i): bool
    {
        $colon = self::previousIndex($tokens, $i);
        if ($colon === null || ! $tokens[$colon]->is(T_DOUBLE_COLON)) {
            return false;
        }
        $class = self::previousIndex($tokens, $colon);

        return $class !== null && in_array(ltrim($tokens[$class]->text, '\\'), ['Lang', 'Illuminate\\Support\\Facades\\Lang'], true);
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{0: PhpToken, 1: ?PhpToken}|null the literal first argument and the token after it
     */
    private static function firstArgument(array $tokens, int $i): ?array
    {
        $open = self::next($tokens, $i);
        if ($open === null || $open[0]->text !== '(') {
            return null;
        }
        $literal = self::next($tokens, $open[1]);
        if ($literal === null || ! $literal[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }
        $after = self::next($tokens, $literal[1]);

        return [$literal[0], $after[0] ?? null];
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{0: PhpToken, 1: int}|null
     */
    private static function next(array $tokens, int $i): ?array
    {
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
            if (! $tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return [$tokens[$j], $j];
            }
        }

        return null;
    }

    /** @param  array<int, PhpToken>  $tokens */
    private static function previous(array $tokens, int $i): ?PhpToken
    {
        $j = self::previousIndex($tokens, $i);

        return $j === null ? null : $tokens[$j];
    }

    /** @param  array<int, PhpToken>  $tokens */
    private static function previousIndex(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $j;
            }
        }

        return null;
    }

    public static function unquote(string $literal): string
    {
        $body = substr($literal, 1, -1);

        return $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body) : stripcslashes($body);
    }
}
