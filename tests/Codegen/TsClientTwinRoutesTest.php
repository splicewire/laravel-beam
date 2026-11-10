<?php

namespace Splicewire\Beam\Tests\Codegen;

use Splicewire\Beam\Codegen\RouteManifestModelSource;
use Splicewire\Beam\Codegen\TsClientGenerator;
use Splicewire\Beam\Source\RouteManifestSource;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

it('keeps both twin query endpoints with distinct hooks and stores while retaining the web short name', function () {
    $source = new class implements RouteManifestSource
    {
        public function toArray(): array
        {
            return [
                'api.v1.beam-ux-entry.body' => [
                    'path' => 'api/v1/entries/{entry}/body',
                    'methods' => ['GET'],
                    'returns' => 'App.Data.EntryBody',
                ],
                'beam-ux-entry.body' => [
                    'path' => 'entries/{entry}/body',
                    'methods' => ['GET'],
                    'returns' => 'App.Data.EntryBody',
                ],
            ];
        }
    };
    $model = (new RouteManifestModelSource($source))->model()->toArray();
    $result = (new TsClientGenerator)->invoke(['model' => $model, 'options' => ['emit_stores' => true]]);
    $files = $result['files'];

    expect($files)->toHaveKeys([
        'hooks/beam-ux-entry.ts',
        'hooks/api-v1-beam-ux-entry.ts',
        'stores/beam-ux-entry.ts',
        'stores/api-v1-beam-ux-entry.ts',
    ])
        ->and($result['stats']['typed'])->toBe(2)
        ->and($files['routes.ts'])
        ->toContain("'api.v1.beam-ux-entry.body'", 'api/v1/entries/{entry}/body')
        ->toContain("'beam-ux-entry.body'", 'entries/{entry}/body');

    expect($files['hooks/beam-ux-entry.ts'])
        ->toContain('export function useBeamUxEntryBody(', "queryKey: ['beam-ux-entry.body', params]", "route('beam-ux-entry.body', params)")
        ->not->toContain('useApiV1BeamUxEntryBody', "route('api.v1.beam-ux-entry.body', params)")
        ->and($files['hooks/api-v1-beam-ux-entry.ts'])
        ->toContain('export function useApiV1BeamUxEntryBody(', "queryKey: ['api.v1.beam-ux-entry.body', params]", "route('api.v1.beam-ux-entry.body', params)")
        ->not->toContain('export function useBeamUxEntryBody(');

    expect($files['stores/beam-ux-entry.ts'])
        ->toContain('type BeamUxEntryBodyState =', 'export const useBeamUxEntryBodyStore =', "route('beam-ux-entry.body')")
        ->and($files['stores/api-v1-beam-ux-entry.ts'])
        ->toContain('type ApiV1BeamUxEntryBodyState =', 'export const useApiV1BeamUxEntryBodyStore =', "route('api.v1.beam-ux-entry.body')");
});
