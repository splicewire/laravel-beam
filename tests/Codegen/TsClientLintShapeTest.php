<?php

namespace Splicewire\Beam\Tests\Codegen;

use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Source\RouteManifestSource;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/**
 * calcucrypt MAP-24: a host that lints its committed `generated/hooks` with the starter's eslint config got 14 errors
 * in 4 files from two rules: `import/consistent-type-specifier-style: prefer-top-level` (type names inline in the
 * value import) and `@stylistic/padding-line-between-statements` (no blank line before a `return` or `try` that
 * follows a statement). The generated client is lint-clean against both now.
 *
 * @return array<string, string>
 */
function lintShapeFiles(): array
{
    $manifest = [
        'gadgets.index' => ['path' => 'gadgets', 'methods' => ['GET'], 'returns' => 'App.Data.GadgetData', 'returnsMany' => true],
        'gadgets.show' => ['path' => 'gadgets/{gadget}', 'methods' => ['GET'], 'returns' => 'App.Data.GadgetData'],
        'gadgets.store' => ['path' => 'gadgets', 'methods' => ['POST'], 'returns' => 'App.Data.GadgetData'],
        'gadgets.update' => ['path' => 'gadgets/{gadget}', 'methods' => ['PUT'], 'returns' => 'App.Data.GadgetData'],
        'gadgets.destroy' => ['path' => 'gadgets/{gadget}', 'methods' => ['DELETE']],
        'widgets.index' => ['path' => 'widgets', 'methods' => ['GET'], 'returns' => 'App.Data.GadgetData', 'returnsMany' => true],
        'levers.store' => ['path' => 'levers', 'methods' => ['POST'], 'returns' => 'App.Data.GadgetData'],
    ];
    $source = new class($manifest) implements RouteManifestSource
    {
        public function __construct(private array $manifest) {}

        public function toArray(): array
        {
            return $this->manifest;
        }
    };
    $model = (new RouteManifestModelSource($source))->model()->toArray();

    return (new TsClientGenerator)->invoke(['model' => $model, 'options' => ['emit_stores' => true]])['files'];
}

it('imports query types in their own top-level type import, apart from the hooks', function () {
    $files = lintShapeFiles();

    expect($files['hooks/gadgets.ts'])
        ->toContain("import { useMutation, useQuery } from '@tanstack/react-query';\n")
        ->toContain("import type { UseMutationOptions, UseQueryOptions } from '@tanstack/react-query';\n")
        ->and($files['hooks/widgets.ts'])
        ->toContain("import { useQuery } from '@tanstack/react-query';\n")
        ->toContain("import type { UseQueryOptions } from '@tanstack/react-query';\n")
        ->and($files['hooks/levers.ts'])
        ->toContain("import { useMutation } from '@tanstack/react-query';\n")
        ->toContain("import type { UseMutationOptions } from '@tanstack/react-query';\n");

    foreach ($files as $path => $source) {
        expect(preg_match('/^import \{[^}]*\btype\b/m', $source))->toBe(0, "{$path} names a type inside a value import");
    }
});

/**
 * The consumer's own check, repeatable: `TS_CLIENT_LINT_DUMP=<dir> vendor/bin/pest --filter=lint-clean` writes the
 * generated files there, for `eslint` and `prettier --check` under a starter's config (generated/** is ignored by the
 * starter's eslint config, so lint them from another directory).
 */
it('dumps the generated client for a consumer lint-clean check when asked', function () {
    $dir = getenv('TS_CLIENT_LINT_DUMP');
    if (! is_string($dir) || $dir === '') {
        $this->markTestSkipped('Set TS_CLIENT_LINT_DUMP to a directory to write the files.');
    }
    foreach (lintShapeFiles() as $path => $source) {
        @mkdir(dirname("{$dir}/{$path}"), 0777, true);
        file_put_contents("{$dir}/{$path}", $source);
    }
    expect(glob("{$dir}/hooks/*.ts"))->not->toBeEmpty();
});

it('puts a blank line before every control statement (return, try, if, …) that follows a statement', function () {
    foreach (lintShapeFiles() as $path => $source) {
        $lines = explode("\n", $source);
        foreach ($lines as $i => $line) {
            if ($i === 0 || ! preg_match('/^\s*(return|try|if|for|while|do|switch|throw)\b/', $line)) {
                continue;
            }
            $previous = rtrim($lines[$i - 1]);
            expect($previous === '' || str_ends_with($previous, '{'))
                ->toBeTrue("{$path}:".($i + 1)." follows `{$previous}`");
        }
    }
});
