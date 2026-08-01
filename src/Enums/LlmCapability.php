<?php

namespace Splicewire\Beam\Enums;

/**
 * The distinct LLM tasks a tenant may steer to different models (TLC-05). Each is a
 * capability key in a tenant's `llm_config.capabilities` map: `chat` is the interactive
 * completion default and the fallback every other TEXT capability degrades to; the rest name
 * a narrower job (auto-titling, quick one-shot prompts, triple extraction, embeddings,
 * composition, image generation) so a tenant can pin a cheaper/stronger model per task.
 *
 * Each capability has an inherent completion {@see Modality} (ticket 07): `image_generation` is an
 * image capability, `embedding` an embedding capability, everything else text. `defaultModel()` and
 * the UI derive the modality from the capability so an unset image cap degrades to the image
 * default — never the text chat model.
 */
enum LlmCapability: string
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

    /** The completion modality this capability produces. */
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

    /** The `app.music.roles` key this capability routes through (music-modality caps only). */
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
