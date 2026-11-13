<?php

namespace Splicewire\Beam\Tests\Localization;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\AssertionFailedError;
use Splicewire\Beam\Localization\Ratchet\LiteralTextCensus;
use Splicewire\Beam\Localization\Ratchet\TextRatchet;
use Splicewire\Beam\Localization\Ratchet\TextRatchetExclusions;
use Splicewire\Beam\Testing\AssertsTextRatchet;
use Splicewire\Beam\Tests\TestCase;

/**
 * localization 12 — the PHP ratchet. User-facing literals in a package's response-reaching calls are counted per file
 * against its committed `lang-ratchet.json`; a count may only fall. Beam's own baseline is committed and compared here.
 */
class TextRatchetTest extends TestCase
{
    use AssertsTextRatchet;

    private function package(string $source): string
    {
        $root = sys_get_temp_dir().'/beam-ratchet-'.getmypid().'-'.bin2hex(random_bytes(4));
        @mkdir($root.'/src', 0777, true);
        file_put_contents($root.'/src/Thing.php', $source);

        return $root;
    }

    public function test_beams_own_literals_never_rise(): void
    {
        $this->assertTextRatchet(dirname(__DIR__, 2));
    }

    public function test_it_counts_the_closed_list_and_never_a_key(): void
    {
        $source = <<<'PHP'
        <?php
        use Illuminate\Notifications\Messages\MailMessage;
        class Paid extends Notification {
            public function f() {
                abort(404, 'Not here');
                abort_unless($ok, 403, __('beam::errors.denied'));
                throw new NotFoundHttpException('Gone');
                throw ValidationException::withMessages(['email' => 'Bad email', 'name' => [__('x::y.z')]]);
                return response()->json(['message' => 'Saved', 'id' => 'abc']);
            }
            public function toMail($n) {
                return (new MailMessage)->subject('Invoice paid')->greeting('Hello!')->line('Thanks '.$n)->action('View', 'https://x');
            }
            public function toArray($n) { return ['type' => 'invoice.paid', 'title' => 'Invoice paid', 'k' => __('a::b.c')]; }
        }
        PHP;

        // abort 1, HttpException 1, withMessages 1, json message 1, mail 4 (subject, greeting, line, action's text),
        // notification values 2; the four keys are never counted.
        $this->assertSame(10, LiteralTextCensus::countFile($source));
    }

    public function test_raising_a_count_fails_and_a_fall_must_be_rebaselined(): void
    {
        $root = $this->package("<?php abort(404, 'Missing');");
        TextRatchet::write($root);
        $this->assertTextRatchet($root);

        file_put_contents($root.'/src/Thing.php', "<?php abort(404, 'Missing'); abort(403, 'Denied');");
        $this->assertSame(['src/Thing.php' => [1, 2]], TextRatchet::compare($root)['rose']);
        try {
            $this->assertTextRatchet($root);
            $this->fail('A raised count must fail the comparison.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('ROSE', $e->getMessage());
        }

        file_put_contents($root.'/src/Thing.php', "<?php abort(404, __('pkg::errors.missing'));");
        $this->assertSame(['src/Thing.php' => [1, 0]], TextRatchet::compare($root)['fell']);

        TextRatchet::write($root);
        $this->assertTextRatchet($root);
    }

    public function test_the_command_compares_without_writing_and_names_its_population(): void
    {
        $root = $this->package("<?php abort(404, 'Missing');");

        $this->assertSame(1, Artisan::call('beam:lang:ratchet', ['root' => $root]));
        $this->assertFileDoesNotExist($root.'/'.TextRatchet::FILE);

        $this->assertSame(0, Artisan::call('beam:lang:ratchet', ['root' => $root, '--write' => true]));
        $this->assertStringContainsString('Population: ', Artisan::output());
        $this->assertSame(0, Artisan::call('beam:lang:ratchet', ['root' => $root]));
        $this->assertStringContainsString('Not read: Blade views', (string) file_get_contents($root.'/'.TextRatchet::FILE));
    }

    public function test_it_catches_interpolated_forwarded_flash_and_data_text_and_only_the_abort_message(): void
    {
        $source = <<<'PHP'
        <?php
        class Thing {
            public function f($e, $id) {
                abort_if($kind === 'draft', 404, "No record {$id}.");
                abort(422, 'Unsupported format: '.implode(', ', $formats).'.');
                session()->flash('status', 'Saved.');
                Session::flash('status', "Saved {$id}.");
                return new NoticeData(id: 'n1', title: 'Heads up', note: "Row {$id} skipped", code: 'x.y');
            }
            public function g() {
                try { run(); } catch (RuntimeException $e) {
                    return response()->json(['message' => $e->getMessage(), 'type' => 'error']);
                }
            }
            public function h() {
                try { run(); } catch (ValidationException $e) {
                    return response()->json(['message' => $e->getMessage()]);
                }
            }
            public function i() {
                return NoticeData::from(['label' => 'Draft', 'kind' => 'draft']);
            }
        }
        PHP;

        $hits = LiteralTextCensus::hitsInSource($source);
        $this->assertSame([
            [4, 'f', 'abort'],            // the message, never the 'draft' in the condition
            [5, 'f', 'abort'],            // one hit for a concatenation
            [6, 'f', 'flash'],            // the message, never the key
            [7, 'f', 'flash'],
            [8, 'f', 'data'],             // title
            [8, 'f', 'data'],             // note (interpolated); id and code are not copy
            [12, 'g', 'json:forwarded'],  // a RuntimeException's message reaches a person
            [21, 'i', 'data'],            // a translated ValidationException's message (h) is not raw text
        ], array_map(static fn (array $h): array => [$h['line'], $h['method'], $h['kind']], $hits));
    }

    public function test_an_exclusion_removes_a_hit_from_the_count_but_reports_it_with_its_reason(): void
    {
        $root = $this->package(<<<'PHP'
        <?php
        class ReplayOp {
            public function assertReplayable($n) {
                abort(422, "Cannot replay {$n}.");
                abort(409, 'Already replayed.');
            }
            public function run() { abort(404, 'Missing'); }
        }
        PHP);
        file_put_contents($root.'/'.TextRatchetExclusions::FILE, json_encode(['exclusions' => [
            ['file' => 'src/Thing.php', 'method' => 'assertReplayable', 'reason' => 'diagnostic, ruling 04'],
        ]]));

        $this->assertSame(['src/Thing.php' => 1], LiteralTextCensus::count($root));
        $excluded = TextRatchet::excluded($root);
        $this->assertCount(2, $excluded);
        $this->assertSame(['assertReplayable', 'diagnostic, ruling 04'], [$excluded[0]['method'], $excluded[0]['reason']]);

        TextRatchet::write($root);
        $this->assertTextRatchet($root);
        $written = json_decode((string) file_get_contents($root.'/'.TextRatchet::FILE), true);
        $this->assertSame(1, $written['total']);
        $this->assertCount(2, $written['excluded']);

        $this->assertSame(0, Artisan::call('beam:lang:ratchet', ['root' => $root]));
        $this->assertStringContainsString('excluded src/Thing.php:4 assertReplayable() (abort): diagnostic, ruling 04', Artisan::output());
    }

    public function test_a_stale_or_malformed_exclusion_fails_the_compare(): void
    {
        $root = $this->package("<?php class T { public function run() { abort(404, 'Missing'); } }");
        TextRatchet::write($root);
        file_put_contents($root.'/'.TextRatchetExclusions::FILE, json_encode(['exclusions' => [
            ['file' => 'src/Thing.php', 'method' => 'gone', 'reason' => 'was a diagnostic'],
            ['file' => 'src/Thing.php', 'line' => 1],
            ['file' => 'src/Thing.php', 'method' => 'run', 'line' => 1, 'reason' => 'both'],
        ]]));

        $result = TextRatchet::compare($root);
        $this->assertSame([['file' => 'src/Thing.php', 'method' => 'gone', 'reason' => 'was a diagnostic']], $result['stale']);
        $this->assertCount(2, $result['invalid']);
        $this->assertSame(1, Artisan::call('beam:lang:ratchet', ['root' => $root]));
        $this->assertStringContainsString('STALE exclusion src/Thing.php::gone', Artisan::output());
        try {
            $this->assertTextRatchet($root);
            $this->fail('A malformed or stale exclusion must fail the comparison.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString(TextRatchetExclusions::FILE, $e->getMessage());
        }
    }
}
