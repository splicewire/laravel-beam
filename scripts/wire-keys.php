#!/usr/bin/env php
<?php

/**
 * Dump every PUBLISHED wire key declared by a `#[MapName]` / `#[MapInputName]` / `#[MapOutputName]`
 * attribute, one line per file, so a refactor can be PROVEN wire-invisible instead of assumed.
 *
 * ## Why this exists
 *
 * `api-surface-coherence` 100 renamed 50 PHP properties across three packages while keeping the
 * attribute. Because the attribute is retained, the published key IS its argument — so the invariant
 * is textual and exact: **the (file, argument) set must be byte-identical before and after.**
 *
 * That is stronger than regenerating JSON schemas and diffing them, because nothing else moving can
 * confound it. A schema diff on a live estate picks up every neighbour's in-flight DTO edit; this
 * picks up exactly the thing being asserted.
 *
 * ## ⚠️ It strips comments FIRST, and that is not cosmetic
 *
 * The first version of this check did not, and matched the same attribute inside docblocks
 * *explaining the convention*. A file whose docblock illustrated `#[MapInputName('expires_in_days')]`
 * reported the key twice; a docblock writing `#[MapInputName('<snake_key>')]` generically reported a
 * key literally named `<snake_key>`. The output read as a botched sweep mid-flight and was a botched
 * checker — one of five instruments in a single day that SUCCEEDED while measuring something other
 * than the question. See `docs/agents/wire-name.convention.md`.
 *
 * ## Usage
 *
 *   php scripts/wire-keys.php ~/Workspaces/php/packages/'*'/'*'/src > before.txt
 *   # …do the rename, keeping every attribute argument untouched…
 *   php scripts/wire-keys.php ~/Workspaces/php/packages/'*'/'*'/src > after.txt
 *   diff before.txt after.txt        # MUST be empty, or a published key moved
 *
 * ⚠️ Quote the globs. zsh does not glob after parameter expansion, so collecting roots into a
 * variable makes every one of them silently match nothing — this estate's own recurring shell trap.
 *
 * For a before/after across a commit rather than across an edit, run it against two checkouts, or
 * feed it `git show <rev>:<path>` output. Comparing HEAD to the worktree is immune to a neighbour's
 * uncommitted edits, which matters on a live estate.
 */
$roots = array_slice($argv, 1);

if ($roots === []) {
    fwrite(STDERR, "usage: wire-keys.php <src-dir> [<src-dir>…]\n");
    exit(2);
}

/** Strip block comments, line comments and doc blocks so only CODE is scanned. */
$codeOnly = static function (string $src): string {
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }

    return $out;
};

$rows = [];

foreach ($roots as $root) {
    if (! is_dir($root)) {
        continue;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $src = @file_get_contents($file->getPathname());

        if ($src === false) {
            continue;
        }

        preg_match_all(
            "/#\[Map(?:Input|Output)?Name\('([^']+)'\)\]/",
            $codeOnly($src),
            $matches,
        );

        if ($matches[1] !== []) {
            $keys = $matches[1];
            sort($keys);
            $rows[] = $file->getPathname()."\t".implode(',', $keys);
        }
    }
}

sort($rows);
echo implode("\n", $rows), "\n";
