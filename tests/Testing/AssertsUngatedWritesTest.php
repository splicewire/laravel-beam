<?php

namespace Splicewire\Beam\Tests\Testing;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Splicewire\Beam\Testing\AssertsUngatedWrites;
use Splicewire\Beam\Tests\Doctor\UngatedWriteFixtureController;
use Splicewire\Beam\Tests\TestCase;

require_once __DIR__.'/../Doctor/UngatedWriteAuditTest.php';

/** The `http.ungated-write` ratchet's own rules (app-walkthrough APP-08): unlisted fails, stale fails, exact passes. */
class AssertsUngatedWritesTest extends TestCase
{
    use AssertsUngatedWrites;

    /** @var array<string, string> */
    private array $ratchet = [];

    protected function ungatedWriteRatchet(): array
    {
        return $this->ratchet;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('ratchet/open', [UngatedWriteFixtureController::class, 'open'])->middleware('auth');
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_an_unlisted_ungated_write_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unlisted ungated write routes');

        $this->assertUngatedWriteRatchet();
    }

    public function test_a_stale_entry_fails_so_the_list_only_shrinks(): void
    {
        $this->ratchet = ['POST ratchet/open' => 'APP-08b', 'POST ratchet/gone' => 'APP-08b'];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Stale entries');

        $this->assertUngatedWriteRatchet();
    }

    public function test_an_exact_match_passes(): void
    {
        $this->ratchet = ['POST ratchet/open' => 'APP-08b: declare an ability'];

        $this->assertUngatedWriteRatchet();
    }
}
