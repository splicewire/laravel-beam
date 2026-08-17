<?php

namespace Splicewire\Beam\Tests\Enums;

use Splicewire\Beam\Enums\LlmTask;
use Splicewire\Beam\Enums\Modality;
use Splicewire\Beam\Tests\TestCase;

/**
 * A task's meter is a code fact (text-to-speech bills per character everywhere); its RATE is a
 * deployment fact and stays in host config. This pins the half that belongs in code — app
 * ADR-0205.
 */
class LlmTaskMeterTest extends TestCase
{
    public function test_fixed_key_tasks_declare_their_meter(): void
    {
        $this->assertSame('search.rerank', LlmTask::Rerank->meter());
        $this->assertSame('audio.tts', LlmTask::TextToSpeech->meter());
        $this->assertSame('audio.stt', LlmTask::SpeechToText->meter());
        $this->assertSame('video.generation', LlmTask::VideoGeneration->meter());
        $this->assertSame('music.generation', LlmTask::MusicGeneration->meter());
        $this->assertSame('image.generation', LlmTask::ImageGeneration->meter());
    }

    /**
     * The three music-role tasks are routing declarations with no producer behind them — nothing
     * calls them, so there is no operation to meter. This pins that as a decision, not an oversight:
     * when a producer lands, this test fails and forces the meter to be declared with it.
     */
    public function test_producerless_music_roles_declare_no_meter(): void
    {
        foreach ([LlmTask::ReferenceCover, LlmTask::VocalSeparation, LlmTask::VoiceConversion] as $task) {
            $this->assertNull($task->meter(), "{$task->value} should not declare a meter until a producer exists");
        }
    }

    /**
     * Text tasks and embeddings meter as `{provider}.tokens`, keyed from the provider resolved on
     * each ledger row — there is no fixed key to declare, and inventing one would be a lie.
     */
    public function test_provider_keyed_tasks_declare_no_fixed_meter(): void
    {
        foreach ([LlmTask::Chat, LlmTask::Title, LlmTask::QuickPrompt, LlmTask::Extraction, LlmTask::Composition] as $task) {
            $this->assertSame(Modality::Text, $task->modality());
            $this->assertNull($task->meter(), "{$task->value} should not declare a fixed meter");
        }

        $this->assertNull(LlmTask::Embedding->meter());
    }

    /** A declared meter never carries a rate — that is the deployment's to set. */
    public function test_a_declared_meter_is_a_key_not_a_price(): void
    {
        foreach (LlmTask::cases() as $task) {
            $meter = $task->meter();

            if ($meter !== null) {
                $this->assertIsString($meter);
                $this->assertStringNotContainsString('usd', $meter);
            }
        }
    }
}
