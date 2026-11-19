<?php

namespace Splicewire\Beam\Tests\Codegen;

use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Source\RouteManifestSource;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * pe08 G9 (d): a document-delivery route — its particle operation declares a `?format` axis (`delivery:` →
 * `formats()`), surfaced as the manifest `urlFormats` key — gets a TYPED URL builder, not a hook. The declared
 * formats ARE the marker (declared, not detected). A route that declares no formats emits no builder; it stays a
 * route-map-only entry, exactly as before. This pins both halves so the gate can't silently flip.
 */

it('emits a typed <domain><Action>Url builder for a urlFormats entry and nothing for an undeclared sibling', function () {
    $source = new class implements RouteManifestSource
    {
        public function toArray(): array
        {
            return [
                // MARKED: the op declared a ?format axis → urlFormats on the entry.
                'examples.export' => ['path' => 'examples/{id}/export', 'methods' => ['GET'], 'urlFormats' => ['pdf', 'docx']],
                // UNMARKED: no returns/streams/urlFormats — a route-map-only entry.
                'examples.index' => ['path' => 'examples', 'methods' => ['GET'], 'unresolved' => true],
            ];
        }
    };

    $model = (new RouteManifestModelSource($source))->model()->toArray();
    $files = (new TsClientGenerator)->invoke(['model' => $model, 'options' => []])['files'];

    $hook = $files['hooks/examples.ts'] ?? '';

    // MARKED emits the builder: a string URL, params typed, `format?` a union of the DECLARED formats, over route().
    expect($hook)->toContain("export function examplesExportUrl(params: { id: string | number }, format?: 'pdf' | 'docx'): string")
        ->and($hook)->toContain("route('examples.export', params)")
        ->and($hook)->toContain("(format ? '?format=' + encodeURIComponent(format) : '')");

    // UNMARKED emits NO builder — a hard fail if the generator stopped gating emission on the declaration.
    expect($hook)->not->toContain('examplesIndexUrl');

    // Both names still land in the route-name map.
    expect($files['routes.ts'])->toContain("'examples.export'")
        ->and($files['routes.ts'])->toContain("'examples.index'");
});

it('invents no URL builder for a format-less route (the unmarked case degrades to route-map only)', function () {
    $source = new class implements RouteManifestSource
    {
        public function toArray(): array
        {
            // The SAME route shape as an export, but WITHOUT a declared format axis: no builder may appear.
            return ['examples.export' => ['path' => 'examples/{id}/export', 'methods' => ['GET'], 'unresolved' => true]];
        }
    };

    $model = (new RouteManifestModelSource($source))->model()->toArray();
    $files = (new TsClientGenerator)->invoke(['model' => $model, 'options' => []])['files'];

    expect($files['hooks/examples.ts'] ?? '')->not->toContain('Url(')
        ->and($files['routes.ts'])->toContain("'examples.export'");
});
