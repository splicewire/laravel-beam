<?php

use Splicewire\Beam\Localization\Ratchet\LiteralTextCensus;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * REVIEW-B7 S1 (12f): a forwarded `$e->getMessage()` is skipped as already translated only when EVERY arm of its catch is
 * a ValidationException or a Coded* exception. One raw arm counts, whatever order the union is spelled in. B1 and B2
 * (11) are in ReviewB7AmendTest.
 */

it('counts a raw arm of a union catch, whichever order it is spelled in', function (string $types): void {
    $source = "<?php try { run(); } catch ({$types} \$e) { response()->json(['message' => \$e->getMessage()]); }";

    expect(LiteralTextCensus::countFile($source))->toBe(1);
})->with(['RuntimeException|ValidationException', 'ValidationException|RuntimeException', '\\RuntimeException|\\Illuminate\\Validation\\ValidationException']);

it('skips a union whose every arm is translated', function (): void {
    $source = "<?php try { run(); } catch (ValidationException|CodedHttpException \$e) { response()->json(['message' => \$e->getMessage()]); }";

    expect(LiteralTextCensus::countFile($source))->toBe(0);
});

it('does not let an earlier translated catch hide a later raw forward', function (): void {
    $source = <<<'PHP'
    <?php
    class Demo {
        public function one() { try { a(); } catch (ValidationException $e) { response()->json(['message' => $e->getMessage()]); } }
        public function two($e) { return response()->json(['message' => $e->getMessage()]); }
    }
    PHP;

    expect(LiteralTextCensus::countFile($source))->toBe(1);
});
