<?php

namespace Splicewire\Beam\Tests\Source;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Source\LadderForeignSourceProjector;

/**
 * The default projector adapter (sourced-particles ticket 06): the beam-side wrapper over ticket-04's
 * `MigrationLadder::forForeignSource()->project()`. Proven framework-free — the ladder + acceptance gate
 * are container-free (opis-backed). Asserts the two contract edges the shadower relies on: an
 * unschematized target is refused (ephemeral-only floor), and a projectable payload comes back reconciled.
 */
class LadderForeignSourceProjectorTest extends TestCase
{
    public function test_an_unschematized_target_is_refused_ephemeral_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new LadderForeignSourceProjector)->project(['anything' => 1], []); // no $id
    }

    public function test_a_projectable_payload_comes_back_reconciled(): void
    {
        // The declarative `x-source` rung maps a nested foreign path onto the local field; the
        // structural floor fills the rest. A permissive target (no required-missing) accepts.
        $target = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://schemas.local/content/note/1',
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'x-source' => ['path' => 'heading.text'],
                ],
            ],
        ];

        $projected = (new LadderForeignSourceProjector)->project(
            ['heading' => ['text' => 'Hello']],
            $target,
        );

        $this->assertIsArray($projected);
        $this->assertSame('Hello', $projected['title'] ?? null);
    }

    public function test_an_unreconcilable_payload_is_refused_never_a_half_formed_shadow(): void
    {
        // A REQUIRED field the foreign payload cannot satisfy (no x-source, no default) fails the
        // acceptance gate → the ladder quarantines → the adapter refuses rather than persist a bad shadow.
        $target = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://schemas.local/content/note/1',
            'type' => 'object',
            'required' => ['title'],
            'properties' => [
                'title' => ['type' => 'string'],
            ],
        ];

        $this->expectException(\RuntimeException::class);
        (new LadderForeignSourceProjector)->project(['unrelated' => 'x'], $target);
    }
}
