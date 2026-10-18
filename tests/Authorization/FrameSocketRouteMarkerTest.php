<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Support\Facades\Route;
use Schemastud\Frame\Http\Controllers\ServesResourceSocket;
use Splicewire\Beam\Authorization\FrameSocketRoute;
use Splicewire\Beam\Tests\TestCase;

class MarkerOptInController implements ServesResourceSocket
{
    public function __invoke(): array
    {
        return [];
    }
}

class UnmarkedController
{
    public function __invoke(): array
    {
        return [];
    }
}

/**
 * A4-C4's win, proven from the consumer side: a FUTURE socket controller that opts in through
 * frame's marker is recognized by beam's write-gate helper with NO beam edit — the hand-maintained
 * three-class list this replaces could not do that (red against it by construction).
 */
class FrameSocketRouteMarkerTest extends TestCase
{
    public function test_a_marker_opted_controller_is_recognized_without_a_beam_edit(): void
    {
        Route::get('/probe-marked', MarkerOptInController::class);
        Route::get('/probe-unmarked', UnmarkedController::class);

        $this->assertTrue(FrameSocketRoute::serves(
            $this->app->make('request')->create('/probe-marked')->setRouteResolver(
                fn () => Route::getRoutes()->match(\Illuminate\Http\Request::create('/probe-marked')),
            ),
        ));

        $this->assertFalse(FrameSocketRoute::serves(
            $this->app->make('request')->create('/probe-unmarked')->setRouteResolver(
                fn () => Route::getRoutes()->match(\Illuminate\Http\Request::create('/probe-unmarked')),
            ),
        ));
    }
}
