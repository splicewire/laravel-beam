<?php

namespace Splicewire\Beam\Enums;

/**
 * The completion modality a model produces (system-models ticket 07). Distinct from a model's
 * `accepts` (its INPUT modality): a `gpt-4o` entry accepts image input but its completion modality
 * is still `text`. Prism normalises beyond LLMs (image, embeddings), so the roles/provider layer
 * segments by this so an image model (Gemini) and a text model (Anthropic) can coexist per tenant.
 *
 * Each modality owns a small, extensible role vocabulary — the provider-portable tiers a tenant may
 * pin per capability.
 */
enum Modality: string
{
    case Text = 'text';
    case Image = 'image';
    case Embedding = 'embedding';
    case Rerank = 'rerank';
    case Speech = 'speech';
    case Transcription = 'transcription';
    case Video = 'video';
    case Music = 'music';

    /** The provider-portable role vocabulary for this modality. */
    public function roles(): array
    {
        return match ($this) {
            self::Text => ['fast', 'reasoning', 'cheap'],
            self::Image => ['standard', 'hi_res'],
            self::Embedding => ['standard', 'high'],
            self::Rerank => ['standard', 'high_precision'],
            // Audio (system-models ticket 10): quality tiers, mirroring image's intent.
            self::Speech => ['standard', 'hd'],
            self::Transcription => ['fast', 'accurate'],
            // Video (system-models ticket 11): mirror image's quality tiers (hi_res = 1080p/pro).
            self::Video => ['standard', 'hi_res'],
            // Music (render-brief-and-voice): the roles ARE the pipeline capabilities, matching the
            // `app.music.roles` floor — a produced track chains generation, cover, separation, conversion.
            self::Music => ['generation', 'reference_cover', 'vocal_separation', 'voice_conversion'],
        };
    }
}
