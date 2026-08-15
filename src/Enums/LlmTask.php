<?php

namespace Splicewire\Beam\Enums;

use Splicewire\Beam\Capabilities\ExternalCapability;

/**
 * The distinct LLM tasks a tenant may steer to different models (TLC-05). Each is a
 * task key in a tenant's `llm_config.capabilities` map: `chat` is the interactive
 * completion default and the fallback every other TEXT task degrades to; the rest name
 * a narrower job (auto-titling, quick one-shot prompts, triple extraction, embeddings,
 * composition, image generation) so a tenant can pin a cheaper/stronger model per task.
 *
 * Each task has an inherent completion {@see Modality} (ticket 07): `image_generation` is an
 * image task, `embedding` an embedding task, everything else text. `defaultModel()` and
 * the UI derive the modality from the task so an unset image task degrades to the image
 * default — never the text chat model.
 *
 * Named LlmTask, NOT LlmCapability (app ADR-0205): this is MODEL ROUTING — which model handles
 * which job — and shares nothing with {@see ExternalCapability},
 * which declares an access axis and a cost axis. "Capability" is the estate's most overloaded
 * noun (three unrelated classes already carry the bare name); this enum never meant that sense
 * of it. The backing STRING VALUES are unchanged — they are persisted in every tenant's
 * `llm_config.capabilities` map, so the rename is source-level only.
 */
enum LlmTask: string
{
    case Chat = 'chat';
    case Title = 'title';
    case QuickPrompt = 'quick_prompt';
    case Extraction = 'extraction';
    case Embedding = 'embedding';
    case Composition = 'composition';
    case ImageGeneration = 'image_generation';
    case Rerank = 'rerank';
    case TextToSpeech = 'text_to_speech';
    case SpeechToText = 'speech_to_text';
    case VideoGeneration = 'video_generation';

    // Music is multi-role (render-brief-and-voice): a produced track is a chain of music-modality
    // capabilities — generate a song, cover it against a reference voice, separate a reference vocal,
    // convert a vocal to a target timbre — each routable to its own model via `app.music`.
    case MusicGeneration = 'music_generation';
    case ReferenceCover = 'music_reference_cover';
    case VocalSeparation = 'music_vocal_separation';
    case VoiceConversion = 'music_voice_conversion';

    /** The completion modality this task produces. */
    public function modality(): Modality
    {
        return match ($this) {
            self::ImageGeneration => Modality::Image,
            self::Embedding => Modality::Embedding,
            self::Rerank => Modality::Rerank,
            self::TextToSpeech => Modality::Speech,
            self::SpeechToText => Modality::Transcription,
            self::VideoGeneration => Modality::Video,
            self::MusicGeneration,
            self::ReferenceCover,
            self::VocalSeparation,
            self::VoiceConversion => Modality::Music,
            default => Modality::Text,
        };
    }

    /** The `app.music.roles` key this task routes through (music-modality tasks only). */
    public function musicRole(): ?string
    {
        return match ($this) {
            self::MusicGeneration => 'generation',
            self::ReferenceCover => 'reference_cover',
            self::VocalSeparation => 'vocal_separation',
            self::VoiceConversion => 'voice_conversion',
            default => null,
        };
    }
}
