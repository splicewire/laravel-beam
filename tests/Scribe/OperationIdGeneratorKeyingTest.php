<?php

namespace Splicewire\Beam\Tests\Scribe;

use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;
use Splicewire\Beam\Scribe\OpenApi\OperationIdGenerator;
use Splicewire\Beam\Tests\TestCase;

/**
 * The two pure statics {@see OperationIdGenerator} rests on, api-surface-coherence 36/78.
 *
 * The class landed untested (recovered from a worktree where it had sat unlanded since 2026-08-26 while
 * its other half — `BeamRouteAction::operationId()` — was committed without it). These cover the two
 * functions whose docblocks make a specific promise, not the Scribe generate path, which needs a real
 * `OutputEndpointData` and a written document.
 *
 * Both promises are the kind that break silently: a wrong `wireKey()` makes rung (E) miss and every route
 * falls to the ugly fallback with nothing failing, and a wrong `wireShape()` emits an id containing
 * characters an SDK generator will mangle — which is the exact vendor bug (`[^\w+]` keeping a literal `+`)
 * this class exists to supersede.
 */
class OperationIdGeneratorKeyingTest extends TestCase
{
    public function test_it_is_a_scribe_openapi_generator(): void
    {
        // If Scribe ever moves this base class the generator silently stops being registrable, so pin it.
        $this->assertTrue(is_subclass_of(OperationIdGenerator::class, OpenApiGenerator::class));
    }

    /**
     * The documented trap: Scribe writes `{param?}` into the document's paths as `{param}` while the
     * router keeps the `?`. Both sides must normalise to the same key or rung (E) never matches.
     */
    public function test_wire_key_drops_the_optional_parameter_marker_so_both_sides_agree(): void
    {
        $fromRouter = OperationIdGenerator::wireKey('GET', 'api/v1/embeds/{embed}/versions/{version?}');
        $fromDocument = OperationIdGenerator::wireKey('GET', 'api/v1/embeds/{embed}/versions/{version}');

        $this->assertSame($fromRouter, $fromDocument);
    }

    public function test_wire_key_lowercases_the_verb_and_strips_a_leading_slash(): void
    {
        $this->assertSame(
            'get api/v1/embeds',
            OperationIdGenerator::wireKey('GET', '/api/v1/embeds'),
        );
    }

    public function test_wire_key_distinguishes_verbs_on_one_uri(): void
    {
        $this->assertNotSame(
            OperationIdGenerator::wireKey('GET', 'api/v1/embeds'),
            OperationIdGenerator::wireKey('POST', 'api/v1/embeds'),
        );
    }

    /** The docblock's own worked example. */
    public function test_wire_shape_camel_cases_verb_and_full_uri(): void
    {
        $this->assertSame(
            'getApiV1EmbedsIdVersions',
            OperationIdGenerator::wireShape('GET', 'api/v1/embeds/{id}/versions'),
        );
    }

    /**
     * ⚠️ The property the fallback exists to hold, and the one "improvement" the docblock forbids:
     * dropping path params would merge these two into one id.
     */
    public function test_wire_shape_keeps_path_params_as_segments_so_siblings_cannot_collide(): void
    {
        $this->assertNotSame(
            OperationIdGenerator::wireShape('GET', 'api/v1/embeds/{id}/versions'),
            OperationIdGenerator::wireShape('GET', 'api/v1/embeds/versions'),
        );
    }

    /**
     * The vendor defect this class supersedes: Scribe's `preg_replace('/[^\w+]/', '', …)` keeps a literal
     * `+`, and four shipped ids carried one. `wireShape()` promises `[A-Za-z0-9]` only — note `\w` would
     * NOT be enough, because it keeps `_`.
     */
    public function test_wire_shape_emits_only_alphanumerics(): void
    {
        $id = OperationIdGenerator::wireShape('GET', 'api/v1/a+b/c_d/{e-f}.json');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]+$/', $id);
    }
}
