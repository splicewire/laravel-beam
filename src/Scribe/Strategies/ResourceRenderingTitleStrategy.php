<?php

namespace Splicewire\Beam\Scribe\Strategies;

use Illuminate\Support\Str;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use Splicewire\Beam\Rendering\ResourceRendering;

/**
 * Title + description for a `Route::resourceRenderings()` mount, off the route's own stamp — and, as the
 * load-bearing side effect, a UNIQUE `operationId` (api-surface-coherence ticket 32 §E).
 *
 * ## Why the title is where the operationId fix lives
 *
 * Scribe derives `operationId` from `metadata->title` and nothing else
 * (`OpenApiSpecGenerators\BaseGenerator::operationId()`), and the OpenAPI document-assembly hooks it
 * exposes receive an `OutputEndpointData` that has already DROPPED the Laravel route — so a generator
 * cannot read the stamp, and a per-route id cannot be written at assembly time. The title is the only
 * seam that reaches both the sidebar and the id.
 *
 * That is what made the duplicate an accident waiting to happen rather than a typo: all three export
 * routes reach ONE controller method, so `GetFromDocBlocks` gave all three its summary — "Read a
 * rendering of the subject. Always mounted." — and all three emitted
 * `readARenderingOfTheSubjectAlwaysMounted`. Duplicate operationIds are invalid OpenAPI; a client
 * generator (ADR-0163) either collides or silently drops two of the three. It afflicts ANY macro-mounted
 * family — one method, N routes — which is why the fix is in the strategy and not in three call sites.
 *
 * The title carries both halves of the mount's identity (the resource and the rendering name), and the
 * macro mounts each (resource, rendering) pair exactly once, so uniqueness is structural rather than
 * hoped for.
 *
 * ## Precedence: this one CLOBBERS the docblock, deliberately
 *
 * Registered after Scribe's `GetFromDocBlocks` and it does NOT defer to an existing title — the opposite
 * of {@see ParticleTitleStrategy}, which always yields to an author's summary. The reasoning is
 * {@see GroupStrategy}'s: the docblock that would win here belongs to a GENERIC controller shared by
 * every rendering of every resource, so it is structurally incapable of describing one route. Yielding to
 * it is what produced three identical sidebar entries. A per-route docblock does not exist and cannot.
 */
class ResourceRenderingTitleStrategy extends Strategy
{
    use ReadsRenderingStamp;

    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): ?array
    {
        $stamp = $this->renderingStamp($endpointData);

        if ($stamp === null) {
            return null; // Not a rendering route — defer to the docblock and particle strategies.
        }

        [$config, $rendering] = $stamp;

        $subject = $this->renderingSubject((string) $config['resource']);
        $action = Str::headline((string) $config['rendering']);
        $ingest = $endpointData->method?->getName() === 'store';

        return [
            'title' => $ingest ? "Ingest {$subject} {$action}" : "{$action} {$subject}",
            'description' => $ingest
                ? $this->ingestDescription($subject, $action)
                : $this->readDescription($subject, $action, $rendering),
        ];
    }

    /**
     * The read verb's prose. The format sentence states the NARROWING explicitly, because the route and
     * the record disagree on purpose: the route accepts the whole union (it is the resource's rendering,
     * not this record's), a record's own profile accepts a subset, and the difference surfaces as a 422
     * rather than a 404 or a silent fallback to the default.
     */
    private function readDescription(string $subject, string $action, ResourceRendering $rendering): string
    {
        $lower = Str::lower($subject);
        $name = Str::lower($action);

        if ($rendering->formats() === []) {
            return "Compile the {$lower} into its `{$name}` rendering. It has a single representation, so "
                .'this endpoint takes no `format` parameter.';
        }

        $default = $this->delivery($rendering)['default'];
        $applied = $default === null ? '' : " Omitting `format` applies `{$default}`.";

        return "Compile the {$lower} into its `{$name}` rendering.{$applied} The route accepts every format "
            ."the rendering can emit; an individual {$lower} may support fewer, and a format it cannot "
            .'emit is rejected with a **422** on `format` — not a 404, and never a silent fallback.';
    }

    private function ingestDescription(string $subject, string $action): string
    {
        $lower = Str::lower($subject);

        return 'Fold an edited `'.Str::lower($action)."` rendering back onto the {$lower}. This verb exists "
            .'only because `RenderingCertifier` PROVED the rendering reversible — a lossy rendering has no '
            .'write route at all.';
    }
}
